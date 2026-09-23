<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Integration tests: a real backup and restore.
 *
 * @package    local_resourcelinkfix
 * @copyright  2026 UFSC
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_resourcelinkfix;

use advanced_testcase;
use backup;
use backup_controller;
use context_module;
use restore_controller;
use restore_dbops;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');

/**
 * Exercises the plugin along the real path: a complete restore.
 *
 * The plugin hooks into /module, not /course, because
 * restore_course_task::build() only adds restore_course_structure_step
 * when the target is a new course. These tests cover both targets.
 *
 * @package    local_resourcelinkfix
 * @copyright  2026 UFSC
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @group      local_resourcelinkfix
 * @covers     \restore_local_resourcelinkfix_plugin
 */
final class restore_test extends advanced_testcase {
    /**
     * Creates a course with an activity and a resource whose HTML and JS
     * point to it.
     *
     * @return array [stdClass $course, int activity cmid, int resource cmid]
     */
    protected function create_source_course() {
        global $USER;

        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['numsections' => 2]);
        $page = $generator->create_module('page', ['course' => $course->id]);
        $resource = $generator->create_module('resource', ['course' => $course->id]);

        $context = context_module::instance($resource->cmid);
        $fs = get_file_storage();
        $base = ['contextid' => $context->id, 'component' => 'mod_resource',
            'filearea' => 'content', 'itemid' => 0, 'filepath' => '/', 'userid' => $USER->id];

        $fs->create_file_from_string(
            array_merge($base, ['filename' => 'index.html', 'sortorder' => 1]),
            '<a href="../../mod/page/view.php?id=' . $page->cmid . '">Atividade</a>'
        );
        $fs->create_file_from_string(
            array_merge($base, ['filename' => 'nav.js', 'sortorder' => 0]),
            "var u = '../../mod/page/view.php?id={$page->cmid}';"
        );

        return [$course, $page->cmid, $resource->cmid];
    }

    /**
     * Backs up a course and returns the extracted directory.
     *
     * @param int $courseid
     * @return string
     */
    protected function make_backup($courseid) {
        global $USER, $CFG;

        $bc = new backup_controller(
            backup::TYPE_1COURSE,
            $courseid,
            backup::FORMAT_MOODLE,
            backup::INTERACTIVE_NO,
            backup::MODE_GENERAL,
            $USER->id
        );
        $bc->execute_plan();
        $results = $bc->get_results();
        $file = $results['backup_destination'];
        $bc->destroy();

        $dir = 'rlf_' . uniqid();
        $path = $CFG->tempdir . '/backup/' . $dir;
        check_dir_exists($path);
        $file->extract_to_pathname(get_file_packer('application/vnd.moodle.backup'), $path);
        return $dir;
    }

    /**
     * Restores an extracted backup into a course.
     *
     * @param string $dir Extracted backup directory.
     * @param int $targetcourseid Target course.
     * @param int $target A backup::TARGET_* constant.
     * @return string Restore id, the key of this restore's rows in backup_logs.
     */
    protected function restore($dir, $targetcourseid, $target) {
        global $USER;

        $rc = new restore_controller(
            $dir,
            $targetcourseid,
            backup::INTERACTIVE_NO,
            backup::MODE_GENERAL,
            $USER->id,
            $target
        );
        $rc->execute_precheck();
        $rc->execute_plan();
        $restoreid = $rc->get_restoreid();
        $rc->destroy();
        return $restoreid;
    }

    /**
     * Content of a file of a course's only mod_resource.
     *
     * @param int $courseid
     * @param string $filename
     * @return string
     */
    protected function file_content($courseid, $filename) {
        global $DB;

        $cmid = $DB->get_field_sql(
            "SELECT cm.id
                                      FROM {course_modules} cm
                                      JOIN {modules} m ON m.id = cm.module
                                     WHERE cm.course = ? AND m.name = 'resource'",
            [$courseid],
            IGNORE_MULTIPLE
        );
        $file = get_file_storage()->get_file(
            context_module::instance($cmid)->id,
            'mod_resource',
            'content',
            0,
            '/',
            $filename
        );
        return $file ? $file->get_content() : '';
    }

    /**
     * Cmid of a course's only page activity.
     *
     * @param int $courseid
     * @return int
     */
    protected function page_cmid($courseid) {
        global $DB;

        return (int)$DB->get_field_sql(
            "SELECT cm.id
                                          FROM {course_modules} cm
                                          JOIN {modules} m ON m.id = cm.module
                                         WHERE cm.course = ? AND m.name = 'page'",
            [$courseid],
            IGNORE_MULTIPLE
        );
    }

    /**
     * Restoring as a new course, the link points to the new cmid.
     */
    public function test_restore_into_new_course_rewrites_the_link() {
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $source = $this->create_source_course();
        $course = $source[0];
        $oldcmid = $source[1];
        $dir = $this->make_backup($course->id);

        $newcourseid = restore_dbops::create_new_course(
            'Destino',
            'destino-' . uniqid(),
            $course->category
        );
        $this->restore($dir, $newcourseid, backup::TARGET_NEW_COURSE);

        $newcmid = $this->page_cmid($newcourseid);
        $this->assertNotEquals($oldcmid, $newcmid);
        $this->assertContains('view.php?id=' . $newcmid, $this->file_content($newcourseid, 'index.html'));
        $this->assertNotContains('view.php?id=' . $oldcmid, $this->file_content($newcourseid, 'index.html'));
    }

    /**
     * Restoring into an existing course, the plugin acts too.
     *
     * This is the case an after_restore_course() would never reach.
     */
    public function test_restore_into_existing_course_rewrites_the_link() {
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $source = $this->create_source_course();
        $course = $source[0];
        $oldcmid = $source[1];
        $dir = $this->make_backup($course->id);

        $target = $this->getDataGenerator()->create_course(['numsections' => 2]);
        $this->restore($dir, $target->id, backup::TARGET_EXISTING_ADDING);

        $newcmid = $this->page_cmid($target->id);
        $this->assertNotEquals($oldcmid, $newcmid);
        $this->assertContains('view.php?id=' . $newcmid, $this->file_content($target->id, 'index.html'));
    }

    /**
     * With the setting off, the .js comes out untouched.
     */
    public function test_js_is_not_touched_by_default() {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        set_config('rewritejs', 0, 'local_resourcelinkfix');

        $source = $this->create_source_course();
        $course = $source[0];
        $oldcmid = $source[1];
        $dir = $this->make_backup($course->id);

        $newcourseid = restore_dbops::create_new_course(
            'Destino js off',
            'djsoff-' . uniqid(),
            $course->category
        );
        $this->restore($dir, $newcourseid, backup::TARGET_NEW_COURSE);

        $newcmid = $this->page_cmid($newcourseid);
        // The HTML was fixed...
        $this->assertContains('view.php?id=' . $newcmid, $this->file_content($newcourseid, 'index.html'));
        // ...and the .js was not.
        $this->assertContains('view.php?id=' . $oldcmid, $this->file_content($newcourseid, 'nav.js'));
    }

    /**
     * With the setting on, the .js is rewritten too.
     */
    public function test_js_is_rewritten_when_enabled() {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        set_config('rewritejs', 1, 'local_resourcelinkfix');

        $source = $this->create_source_course();
        $course = $source[0];
        $oldcmid = $source[1];
        $dir = $this->make_backup($course->id);

        $newcourseid = restore_dbops::create_new_course(
            'Destino js on',
            'djson-' . uniqid(),
            $course->category
        );
        $this->restore($dir, $newcourseid, backup::TARGET_NEW_COURSE);

        $newcmid = $this->page_cmid($newcourseid);
        $this->assertContains('view.php?id=' . $newcmid, $this->file_content($newcourseid, 'nav.js'));
        $this->assertNotContains('view.php?id=' . $oldcmid, $this->file_content($newcourseid, 'nav.js'));
    }

    /**
     * Disabled, the plugin does nothing: restore behaves as if it did not
     * exist.
     */
    public function test_disabled_touches_nothing() {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        set_config('enabled', 0, 'local_resourcelinkfix');

        $source = $this->create_source_course();
        $course = $source[0];
        $oldcmid = $source[1];
        $dir = $this->make_backup($course->id);

        $newcourseid = restore_dbops::create_new_course(
            'Destino off',
            'doff-' . uniqid(),
            $course->category
        );
        $this->restore($dir, $newcourseid, backup::TARGET_NEW_COURSE);

        $this->assertContains('view.php?id=' . $oldcmid, $this->file_content($newcourseid, 'index.html'));
    }

    /**
     * The main file is still the main file after the rewrite.
     */
    public function test_sortorder_is_preserved() {
        global $DB;

        $this->resetAfterTest(true);
        $this->setAdminUser();

        $source = $this->create_source_course();
        $course = $source[0];
        $dir = $this->make_backup($course->id);

        $newcourseid = restore_dbops::create_new_course(
            'Destino so',
            'dso-' . uniqid(),
            $course->category
        );
        $this->restore($dir, $newcourseid, backup::TARGET_NEW_COURSE);

        $cmid = $DB->get_field_sql(
            "SELECT cm.id
                                      FROM {course_modules} cm
                                      JOIN {modules} m ON m.id = cm.module
                                     WHERE cm.course = ? AND m.name = 'resource'",
            [$newcourseid],
            IGNORE_MULTIPLE
        );
        $file = get_file_storage()->get_file(
            context_module::instance($cmid)->id,
            'mod_resource',
            'content',
            0,
            '/',
            'index.html'
        );
        $this->assertEquals(1, $file->get_sortorder());
    }

    /**
     * Logger level x mode: the six corners of what reaches the restore log.
     *
     * @return array[] [logger level, simulation on, simulation messages, rewrite messages]
     */
    public static function log_level_provider() {
        return [
            'simulation, level ERROR' => [backup::LOG_ERROR, 1, 0, 0],
            'simulation, level WARNING (default)' => [backup::LOG_WARNING, 1, 1, 0],
            'simulation, level INFO' => [backup::LOG_INFO, 1, 1, 0],
            'rewrite, level ERROR' => [backup::LOG_ERROR, 0, 0, 0],
            'rewrite, level WARNING (default)' => [backup::LOG_WARNING, 0, 0, 0],
            'rewrite, level INFO' => [backup::LOG_INFO, 0, 0, 1],
        ];
    }

    /**
     * The simulation is visible at Moodle's default log level; the rewrite
     * report stays at INFO so a normal restore does not flood the log.
     *
     * @dataProvider log_level_provider
     * @param int $level Database logger level, as $CFG->backup_database_logger_level.
     * @param int $dryrun Simulation mode setting.
     * @param int $simulation Expected simulation messages.
     * @param int $rewritten Expected rewrite messages.
     */
    public function test_log_level_of_messages($level, $dryrun, $simulation, $rewritten) {
        global $CFG, $DB;

        $this->resetAfterTest(true);
        $this->setAdminUser();
        $CFG->backup_database_logger_level = $level;
        set_config('dryrun', $dryrun, 'local_resourcelinkfix');

        $source = $this->create_source_course();
        $course = $source[0];
        $oldcmid = $source[1];
        $dir = $this->make_backup($course->id);

        $newcourseid = restore_dbops::create_new_course(
            'Destino log',
            'dlog-' . uniqid(),
            $course->category
        );
        $restoreid = $this->restore($dir, $newcourseid, backup::TARGET_NEW_COURSE);

        $count = function ($prefix) use ($DB, $restoreid) {
            return $DB->count_records_select(
                'backup_logs',
                'backupid = ? AND ' . $DB->sql_like('message', '?'),
                [$restoreid, $DB->sql_like_escape($prefix) . '%']
            );
        };
        $this->assertSame($simulation, $count('local_resourcelinkfix [SIMULATION]:'));
        $this->assertSame($rewritten, $count('local_resourcelinkfix: '));

        // Simulation writes nothing, whatever the log level.
        $expected = $dryrun ? $oldcmid : $this->page_cmid($newcourseid);
        $this->assertSame(
            1,
            substr_count($this->file_content($newcourseid, 'index.html'), 'view.php?id=' . $expected . '"')
        );
    }
}
