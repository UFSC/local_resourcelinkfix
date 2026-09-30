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
 * Restore plugin whose rewrite always fails.
 *
 * @package    local_resourcelinkfix
 * @copyright  2026 UFSC
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/resourcelinkfix/tests/fixtures/testable_plugin.php');

/**
 * Plugin whose rewrite always fails, running on a fake task.
 *
 * @package    local_resourcelinkfix
 * @copyright  2026 UFSC
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class local_resourcelinkfix_logging_plugin extends local_resourcelinkfix_testable_plugin {
    /**
     * Sets the task the plugin reads and logs through.
     *
     * @param local_resourcelinkfix_fake_task $task
     */
    public function set_task($task) {
        $this->task = $task;
    }

    /**
     * A non-empty map, so after_restore_module() reaches the files.
     *
     * @return array
     */
    protected function get_cmmap() {
        return [1 => 2];
    }

    /**
     * Fails the way a PCRE abort does.
     *
     * @param file_storage $fs
     * @param stored_file $file
     * @throws moodle_exception Always.
     */
    protected function rewrite_file($fs, $file) {
        throw new moodle_exception('errorpcre', 'local_resourcelinkfix', '', PREG_BACKTRACK_LIMIT_ERROR);
    }

    /**
     * Exposes log_content() to the tests.
     *
     * @param string $label
     * @param string $content
     */
    public function log_content_for_test($label, $content) {
        $this->log_content($label, $content);
    }
}
