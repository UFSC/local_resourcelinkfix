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
 * Tests for the texts the plugin writes to the restore log.
 *
 * @package    local_resourcelinkfix
 * @copyright  2026 UFSC
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_resourcelinkfix;

use advanced_testcase;
use backup;
use context_module;
use local_resourcelinkfix_fake_task;
use local_resourcelinkfix_logging_plugin;
use local_resourcelinkfix_memory_logger;
use moodle_exception;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/resourcelinkfix/tests/fixtures/log_fixtures.php');

/**
 * Every text the plugin logs comes from the language pack.
 *
 * @package    local_resourcelinkfix
 * @copyright  2026 UFSC
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @group      local_resourcelinkfix
 * @covers     \restore_local_resourcelinkfix_plugin
 */
final class log_messages_test extends advanced_testcase {
    /**
     * Switches to the string manager that marks what get_string() returns.
     */
    protected function setUp() {
        global $CFG;
        $CFG->config_php_settings['customstringmanager'] = 'local_resourcelinkfix_marked_string_manager';
        get_string_manager(true);
    }

    /**
     * Goes back to the standard string manager.
     */
    protected function tearDown() {
        global $CFG;
        unset($CFG->config_php_settings['customstringmanager']);
        get_string_manager(true);
    }

    /**
     * The warning for a file that could not be rewritten comes from get_string().
     */
    public function test_rewrite_failure_message_comes_from_the_language_pack() {
        global $USER;

        $this->resetAfterTest(true);
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $resource = $this->getDataGenerator()->create_module('resource', ['course' => $course->id]);
        get_file_storage()->create_file_from_string([
            'contextid' => context_module::instance($resource->cmid)->id,
            'component' => 'mod_resource', 'filearea' => 'content', 'itemid' => 0,
            'filepath' => '/', 'filename' => 'index.html', 'userid' => $USER->id,
        ], '<a href="../../mod/page/view.php?id=1">Atividade</a>');

        $logger = new local_resourcelinkfix_memory_logger();
        $plugin = new local_resourcelinkfix_logging_plugin();
        $plugin->set_task(new local_resourcelinkfix_fake_task($resource->cmid, $course->id, $logger));
        $plugin->after_restore_module();

        $error = new moodle_exception('errorpcre', 'local_resourcelinkfix', '', PREG_BACKTRACK_LIMIT_ERROR);
        $expected = get_string('errorrewritefailed', 'local_resourcelinkfix', (object)[
            'file' => '/index.html',
            'cmid' => (int)$resource->cmid,
            'error' => $error->getMessage(),
        ]);
        $this->assertSame([[$expected, backup::LOG_WARNING]], $logger->messages);
    }

    /**
     * Content size around the 8192-byte cap of the logged content.
     *
     * @return array[] [content size, expected truncation notices]
     */
    public static function content_size_provider() {
        return [
            'empty' => [0, 0],
            'one byte' => [1, 0],
            'one below the cap' => [8191, 0],
            'at the cap' => [8192, 0],
            'one above the cap' => [8193, 1],
            'well above the cap' => [20000, 1],
        ];
    }

    /**
     * Content beyond the cap ends with a truncation notice from get_string().
     *
     * @dataProvider content_size_provider
     * @param int $size Content size in bytes.
     * @param int $notices Expected truncation notices.
     */
    public function test_truncation_notice_comes_from_the_language_pack($size, $notices) {
        $logger = new local_resourcelinkfix_memory_logger();
        $plugin = new local_resourcelinkfix_logging_plugin();
        $plugin->set_task(new local_resourcelinkfix_fake_task(0, 0, $logger));
        $plugin->log_content_for_test('BEFORE', str_repeat('x', $size));

        $expected = get_string('logtruncated', 'local_resourcelinkfix', (object)[
            'label' => 'BEFORE',
            'limit' => 8192,
            'size' => $size,
        ]);
        $found = 0;
        $mentions = 0;
        foreach ($logger->messages as $entry) {
            if ($entry[0] === $expected) {
                $this->assertSame(backup::LOG_ERROR, $entry[1]);
                $found++;
            }
            if (strpos($entry[0], 'truncated') !== false) {
                $mentions++;
            }
        }
        $this->assertSame($notices, $found);
        // No other line may announce a truncation, literal or not.
        $this->assertSame($notices, $mentions);
    }
}
