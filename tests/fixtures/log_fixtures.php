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
 * Doubles for the restore log tests.
 *
 * @package    local_resourcelinkfix
 * @copyright  2026 UFSC
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/resourcelinkfix/tests/fixtures/testable_plugin.php');

/**
 * String manager that wraps every string it returns in markers.
 *
 * The English strings read exactly like the literal texts they replace, so
 * comparing text alone cannot tell get_string() from a literal. With this
 * manager active, only a message that went through get_string() carries the
 * markers.
 *
 * @package    local_resourcelinkfix
 * @copyright  2026 UFSC
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class local_resourcelinkfix_marked_string_manager extends core_string_manager_standard {
    /**
     * Returns the string wrapped in markers.
     *
     * @param string $identifier
     * @param string $component
     * @param string|object|array $a
     * @param string $lang
     * @return string
     */
    public function get_string($identifier, $component = '', $a = null, $lang = null) {
        return '<<' . parent::get_string($identifier, $component, $a, $lang) . '>>';
    }
}

/**
 * Logger that keeps the messages in memory.
 *
 * @package    local_resourcelinkfix
 * @copyright  2026 UFSC
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class local_resourcelinkfix_memory_logger {
    /** @var array[] Each entry is [message, level]. */
    public $messages = [];

    /**
     * Records a message, as base_logger::process() would.
     *
     * @param string $message
     * @param int $level
     * @param array|null $options
     */
    public function process($message, $level, $options = null) {
        $this->messages[] = [$message, $level];
    }
}

/**
 * The part of restore_activity_task the plugin reads.
 *
 * @package    local_resourcelinkfix
 * @copyright  2026 UFSC
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class local_resourcelinkfix_fake_task {
    /** @var int Course module id of the restored activity. */
    protected $moduleid;

    /** @var int Id of the course restored here. */
    protected $courseid;

    /** @var local_resourcelinkfix_memory_logger */
    protected $logger;

    /**
     * Builds the task of a restored File resource.
     *
     * @param int $moduleid
     * @param int $courseid
     * @param local_resourcelinkfix_memory_logger $logger
     */
    public function __construct($moduleid, $courseid, $logger) {
        $this->moduleid = $moduleid;
        $this->courseid = $courseid;
        $this->logger = $logger;
    }

    /**
     * Name of the restored module.
     *
     * @return string
     */
    public function get_modulename() {
        return 'resource';
    }

    /**
     * Course module id of the restored activity.
     *
     * @return int
     */
    public function get_moduleid() {
        return $this->moduleid;
    }

    /**
     * Id of the course restored here.
     *
     * @return int
     */
    public function get_courseid() {
        return $this->courseid;
    }

    /**
     * Id of the course on the source site.
     *
     * @return int
     */
    public function get_old_courseid() {
        return $this->courseid;
    }

    /**
     * Backup information: a backup made on this same site.
     *
     * @return stdClass
     */
    public function get_info() {
        global $CFG;
        return (object)['original_wwwroot' => $CFG->wwwroot];
    }

    /**
     * The restore logger.
     *
     * @return local_resourcelinkfix_memory_logger
     */
    public function get_logger() {
        return $this->logger;
    }
}

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
