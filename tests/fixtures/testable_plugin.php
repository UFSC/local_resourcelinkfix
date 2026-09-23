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
 * Test subclass of the restore plugin.
 *
 * @package    local_resourcelinkfix
 * @copyright  2026 UFSC
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');
require_once($CFG->dirroot .
    '/local/resourcelinkfix/backup/moodle2/restore_local_resourcelinkfix_plugin.class.php');

/**
 * Exposes the rewriting logic without a restore in progress.
 *
 * Moodle only instantiates the real class in the middle of a restore, with a
 * step and a task. Here the constructor is replaced and the state injected, so
 * the rewriting can be exercised on its own. The integration test
 * (restore_test.php) covers the full path.
 *
 * @package    local_resourcelinkfix
 * @copyright  2026 UFSC
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class local_resourcelinkfix_testable_plugin extends restore_local_resourcelinkfix_plugin {
    /**
     * Skips the parent constructor, which needs the restore step.
     */
    public function __construct() {
        // Nothing to do: the state comes through set_restore_state().
    }

    /**
     * Injects the state the plugin normally reads from the task and backup_ids_temp.
     *
     * @param array $state Keys: cmmap, oldcourseid, newcourseid, oldwwwroot,
     *                     newwwwroot, rewritejs, dryrun.
     */
    public function set_restore_state(array $state) {
        $allowed = ['cmmap', 'oldcourseid', 'newcourseid', 'oldwwwroot',
            'newwwwroot', 'rewritejs', 'dryrun'];
        foreach ($allowed as $name) {
            if (array_key_exists($name, $state)) {
                $this->$name = $state[$name];
            }
        }
    }

    /**
     * Exposes rewrite_links() to the tests.
     *
     * @param string $content
     * @return string
     */
    public function rewrite($content) {
        return $this->rewrite_links($content);
    }

    /**
     * Exposes should_process_file() to the tests.
     *
     * @param string $filename
     * @return bool
     */
    public function processes($filename) {
        return $this->should_process_file($filename);
    }

    /**
     * How many links the last rewrite() call replaced.
     *
     * @return int
     */
    public function get_linkcount() {
        return $this->linkcount;
    }

    /**
     * Exercises the rewrite_file() path that decides to write or refuse,
     * without a stored_file or a restore in progress.
     *
     * Returns the content that would be written, or throws the same exception
     * rewrite_file() would.
     *
     * @param string $old
     * @return string|null Null when nothing would be written.
     * @throws moodle_exception When PCRE aborts.
     */
    public function rewrite_file_for_test($old) {
        $new = $this->rewrite_links($old);

        if ($new === null) {
            throw new moodle_exception(
                'errorpcre',
                'local_resourcelinkfix',
                '',
                preg_last_error()
            );
        }
        if ($new === $old) {
            return null;
        }
        if (!$this->only_links_changed($old, $new)) {
            return null;
        }
        return $new;
    }

    /**
     * The guard: does the new content differ from the old only in the links?
     *
     * @param string $old
     * @param string $new
     * @return bool
     */
    public function only_links_differ($old, $new) {
        return $this->only_links_changed($old, $new);
    }
}
