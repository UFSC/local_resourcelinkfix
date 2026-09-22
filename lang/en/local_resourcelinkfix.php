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
 * English strings for local_resourcelinkfix.
 *
 * @package    local_resourcelinkfix
 * @copyright  2026 UFSC
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'Resource link fix (restore)';
$string['privacy:metadata'] = 'The plugin does not store any personal data.';

$string['enabled'] = 'Enabled';
$string['enabled_desc'] = 'Rewrite activity links inside the HTML files of File resources
(mod_resource) during restore. When disabled, the plugin does nothing and restore behaves
exactly as it would without it.';

$string['rewritejs'] = 'Also rewrite .js files';
$string['rewritejs_desc'] = 'Rewrite links inside <code>.js</code> files as well, not only HTML.
Only complete URLs with a numeric id are touched, so links built at run time
(<code>\'view.php?id=\' + cmid</code>) are never altered. A .js file is code: measure the impact
with simulation mode before enabling this.';

$string['dryrun'] = 'Simulation mode (do not write)';
$string['dryrun_desc'] = 'Only report, in the restore log, which files <em>would</em> be
rewritten and how many links each one has. No file is changed. Useful for measuring the
impact on a real course before enabling the rewrite.';

$string['logrewritten'] = 'local_resourcelinkfix: {$a->file} (cmid {$a->cmid}): {$a->links} link(s) rewritten';
$string['logdryrun'] = 'local_resourcelinkfix [SIMULATION]: {$a->file} (cmid {$a->cmid}): {$a->links} link(s) would be rewritten';
$string['errorpcre'] = 'The regular expression engine gave up on this file (PCRE error {$a}); the file was left untouched.';

$string['errorcontentlost'] = 'Content loss prevented in {$a->file} (cmid {$a->cmid}): the rewrite changed more than the links'
    . ' ({$a->oldsize} bytes before, {$a->newsize} after). The file was left untouched; both versions'
    . ' follow in the log.';
