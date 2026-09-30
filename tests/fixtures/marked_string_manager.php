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
 * String manager that marks what get_string() returns.
 *
 * @package    local_resourcelinkfix
 * @copyright  2026 UFSC
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

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
