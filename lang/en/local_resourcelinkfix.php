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

$string['cli_covered'] = 'reached by the plugin';
$string['cli_examples'] = 'examples:';
$string['cli_examplesof'] = 'examples, {$a}:';
$string['cli_help'] = '
Measures the activity links inside File resources (mod_resource) and reports how many
local_resourcelinkfix reaches. Changes nothing.

Use it to decide, with a number instead of a guess, whether to turn on rewriting of .js
files, and to know in advance what will be left out.

Options:
  -h, --help          Shows this help.
  -c, --course=ID     Limits to one course. Without it, scans the whole site.
  -e, --examples=N    How many examples to show for each case (default 5).
  -j, --js            Includes the analysis of .js files.

Examples:
  php local/resourcelinkfix/cli/measure_links.php
  php local/resourcelinkfix/cli/measure_links.php --js
  php local/resourcelinkfix/cli/measure_links.php --course=42 --js --examples=10
';
$string['cli_hosts'] = 'hosts:';
$string['cli_htmlfiles'] = 'distinct HTML files';
$string['cli_htmllinks'] = 'Links in HTML';
$string['cli_htmlwithlinks'] = 'of these, with any link';
$string['cli_idnotfirst'] = 'missed: id not in first position';
$string['cli_jsbuilt'] = 'built at run time';
$string['cli_jsfiles'] = 'distinct .js files';
$string['cli_jsheading'] = '.js files';
$string['cli_jsliteral'] = 'literal URL (reachable)';
$string['cli_jsnote'] = '  Links in .js are only rewritten with the \'Also rewrite .js files\' option on,
  and only the literal ones. Measure first with simulation mode.';
$string['cli_jsoccurrences'] = 'pattern occurrences';
$string['cli_jsunclassified'] = 'unclassified';
$string['cli_jswithlinks'] = 'of these, with an activity link';
$string['cli_missing'] = '{$a} file(s) with no content in the filedir were skipped.
This happens when the database came from another installation without its moodledata:
the measurement then covers only part of the content.';
$string['cli_otherhost'] = 'preserved: host of another site';
$string['cli_otherscript'] = 'missed: script outside the pattern';
$string['cli_relative'] = '(relative)';
$string['cli_resourcescourse'] = 'Resources analysed (course {$a})';
$string['cli_resourcessite'] = 'Resources analysed (whole site)';
$string['cli_topscripts'] = 'most linked scripts:';
$string['cli_total'] = 'total';
$string['dryrun'] = 'Simulation mode (do not write)';
$string['dryrun_desc'] = 'Only report, in the restore log, which files <em>would</em> be
rewritten and how many links each one has. No file is changed. Useful for measuring the
impact on a real course before enabling the rewrite.';
$string['enabled'] = 'Enabled';
$string['enabled_desc'] = 'Rewrite activity links inside the HTML files of File resources
(mod_resource) during restore. When disabled, the plugin does nothing and restore behaves
exactly as it would without it.';
$string['errorcontentlost'] = 'Content loss prevented in {$a->file} (cmid {$a->cmid}): the rewrite changed more than the links'
    . ' ({$a->oldsize} bytes before, {$a->newsize} after). The file was left untouched; both versions'
    . ' follow in the log.';
$string['errorpcre'] = 'The regular expression engine gave up on this file (PCRE error {$a}); the file was left untouched.';
$string['logdryrun'] = 'local_resourcelinkfix [SIMULATION]: {$a->file} (cmid {$a->cmid}): {$a->links} link(s) would be rewritten';
$string['logrewritten'] = 'local_resourcelinkfix: {$a->file} (cmid {$a->cmid}): {$a->links} link(s) rewritten';
$string['pluginname'] = 'Resource link fix (restore)';
$string['privacy:metadata'] = 'The plugin does not store any personal data.';
$string['rewritejs'] = 'Also rewrite .js files';
$string['rewritejs_desc'] = 'Rewrite links inside <code>.js</code> files as well, not only HTML.
Only complete URLs with a numeric id are touched, so links built at run time
(<code>\'view.php?id=\' + cmid</code>) are never altered. A .js file is code: measure the impact
with simulation mode before enabling this.';
