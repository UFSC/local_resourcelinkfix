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
 * Stand-in for the restore task the plugin reads.
 *
 * @package    local_resourcelinkfix
 * @copyright  2026 UFSC
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

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
