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
 * Settings for local_resourcelinkfix.
 *
 * @package    local_resourcelinkfix
 * @copyright  2026 UFSC
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {

    $settings = new admin_settingpage('local_resourcelinkfix',
        get_string('pluginname', 'local_resourcelinkfix'));
    $ADMIN->add('localplugins', $settings);

    $settings->add(new admin_setting_configcheckbox(
        'local_resourcelinkfix/enabled',
        get_string('enabled', 'local_resourcelinkfix'),
        get_string('enabled_desc', 'local_resourcelinkfix'),
        1));

    $settings->add(new admin_setting_configcheckbox(
        'local_resourcelinkfix/rewritejs',
        get_string('rewritejs', 'local_resourcelinkfix'),
        get_string('rewritejs_desc', 'local_resourcelinkfix'),
        0));

    $settings->add(new admin_setting_configcheckbox(
        'local_resourcelinkfix/dryrun',
        get_string('dryrun', 'local_resourcelinkfix'),
        get_string('dryrun_desc', 'local_resourcelinkfix'),
        0));
}
