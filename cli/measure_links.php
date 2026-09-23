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
 * Measures how many activity links the resources hold and how many the plugin
 * reaches. Read-only: no file is changed.
 *
 * @package    local_resourcelinkfix
 * @copyright  2026 UFSC
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');
require_once($CFG->dirroot .
    '/local/resourcelinkfix/backup/moodle2/restore_local_resourcelinkfix_plugin.class.php');

// No destructuring: PHP 5.6 (Moodle 3.0) lacks [...] =, and moodle-cs forbids list().
$params = cli_get_params(
    ['help' => false, 'course' => 0, 'examples' => 5, 'js' => false],
    ['h' => 'help', 'c' => 'course', 'e' => 'examples', 'j' => 'js']
);
$options = $params[0];
$unrecognized = $params[1];

if ($unrecognized) {
    cli_error(get_string('cliunknowoption', 'admin', implode("\n  ", $unrecognized)));
}

if ($options['help']) {
    echo get_string('cli_help', 'local_resourcelinkfix');
    exit(0);
}

$courseid = (int)$options['course'];
$maxexamples = max(0, (int)$options['examples']);
$withjs = !empty($options['js']);

// The same pattern the plugin uses to rewrite.
$pattern = restore_local_resourcelinkfix_plugin::get_link_pattern();
// Wide pattern: any link to a Moodle script, reachable or not.
$anylink = '~(?:https?://[a-z0-9.\-]+)?/?((?:mod/[a-z0-9_]+/[a-z0-9_]+|course/view|user/view)\.php)\?([^"\'\s>]*)~i';

/**
 * Returns the relevant files of the mod_resource content area.
 *
 * @param string $like SQL filter for the file name.
 * @param int $courseid Zero for the whole site.
 * @return array
 */
function local_resourcelinkfix_fetch_files($like, $courseid) {
    global $DB;

    $params = ['like1' => $like];
    $where = "f.component = 'mod_resource' AND f.filearea = 'content'
              AND f.filesize > 0 AND " . $DB->sql_like('f.filename', ':like1', false);
    $from = "{files} f";
    if ($courseid) {
        $from .= " JOIN {context} ctx ON ctx.id = f.contextid AND ctx.contextlevel = :ctxmod
                   JOIN {course_modules} cm ON cm.id = ctx.instanceid AND cm.course = :courseid";
        $params['ctxmod'] = CONTEXT_MODULE;
        $params['courseid'] = $courseid;
    }
    return $DB->get_records_sql(
        "SELECT f.id, f.contenthash, f.filename FROM {$from} WHERE {$where}",
        $params
    );
}

/**
 * Reads the contents, skipping what is repeated or missing from the filedir.
 *
 * @param array $records
 * @param array $seen Hashes already seen, by reference.
 * @param int $missing Count of missing files, by reference.
 * @return array contenthash => content
 */
function local_resourcelinkfix_read_contents($records, &$seen, &$missing) {
    $fs = get_file_storage();
    $out = [];
    foreach ($records as $record) {
        if (isset($seen[$record->contenthash])) {
            continue;
        }
        $file = $fs->get_file_by_id($record->id);
        if (!$file) {
            continue;
        }
        try {
            $out[$record->contenthash] = $file->get_content();
        } catch (Exception $e) {
            $missing++;
            continue;
        }
        $seen[$record->contenthash] = true;
    }
    return $out;
}

/**
 * Prints a count line with a percentage.
 *
 * @param string $label
 * @param int $value
 * @param int $total
 */
function local_resourcelinkfix_line($label, $value, $total) {
    $pct = $total > 0 ? sprintf('  (%5.1f%%)', 100 * $value / $total) : '';
    // Pad by characters, not bytes: printf counts an accented letter twice.
    $padding = str_repeat(' ', max(0, 42 - core_text::strlen($label)));
    printf("  %s%s %7d%s\n", $label, $padding, $value, $pct);
}

// One set of hashes per phase: HTML and .js are counted separately.
// Sharing the same array would skip a .js whose content matches some .html,
// undercounting the files.
$seenhtml = [];
$seenjs = [];
$missing = 0;

cli_heading($courseid
    ? get_string('cli_resourcescourse', 'local_resourcelinkfix', $courseid)
    : get_string('cli_resourcessite', 'local_resourcelinkfix'));

// HTML files.
$htmlrecords = array_merge(
    local_resourcelinkfix_fetch_files('%.html', $courseid),
    local_resourcelinkfix_fetch_files('%.htm', $courseid)
);
$htmlfiles = local_resourcelinkfix_read_contents($htmlrecords, $seenhtml, $missing);

$stats = ['total' => 0, 'covered' => 0, 'otherhost' => 0,
    'idnotfirst' => 0, 'otherscript' => 0];
$scripts = [];
$hosts = [];
$escaped = [];
$withlinks = 0;

foreach ($htmlfiles as $content) {
    // The plugin rewrites the whole file, not just attributes: a link in an
    // inline script, in onclick, in a CSS url() or in running text counts the
    // same. Measuring only href/src would report less than will be changed.
    // The absolute prefix is anchored and bounded on purpose: a greedy
    // '[^\s]*' in front backtracks quadratically when the file has a long run
    // without spaces (an image embedded in base64): measured at 10.5 s for
    // 30 KB, against 1.3 ms in this form.
    // The anchor follows the plugin's ((?<![a-z0-9_])), so as not to miss a
    // link it rewrites - an unquoted attribute, after a comma, etc.
    if (
        !preg_match_all(
            '~(?:(?:https?:)?//[^\s"\'<>()]{0,400})?(?<![a-z0-9_])'
            . '(?:mod/[a-z0-9_]+/[a-z0-9_]+|course/view|user/view)\.php\?[^"\'\s>)]{0,400}~i',
            $content,
            $foundurls
        )
    ) {
        continue;
    }
    $found = false;
    foreach ($foundurls[0] as $url) {
        if (!preg_match($anylink, $url, $parts)) {
            continue;
        }
        $found = true;
        $stats['total']++;
        $script = strtolower(ltrim($parts[1], '/'));
        $scripts[$script] = isset($scripts[$script]) ? $scripts[$script] + 1 : 1;
        // Accepts a port and scheme-less URLs, like the plugin's pattern:
        // otherwise the report's two tables would disagree.
        $host = preg_match('~^((?:https?:)?//[^\s/"\'<>]+)/~i', $url, $h)
            ? strtolower($h[1]) : get_string('cli_relative', 'local_resourcelinkfix');
        $hosts[$host] = isset($hosts[$host]) ? $hosts[$host] + 1 : 1;

        if (preg_match($pattern, $url, $hit)) {
            // Matching the pattern is not enough: an absolute link to another
            // site is deliberately preserved by the plugin. Counting it as
            // covered would inflate the percentage with what will never be rewritten.
            $linkhost = isset($hit[1]) ? preg_replace('/[ \t]+|-[ \t]+/', '', rtrim($hit[1], '/')) : '';
            if ($linkhost === '' || strcasecmp($linkhost, rtrim($CFG->wwwroot, '/')) === 0) {
                $stats['covered']++;
            } else {
                $stats['otherhost']++;
                if (!isset($escaped['otherhost'])) {
                    $escaped['otherhost'] = [];
                }
                if (count($escaped['otherhost']) < $maxexamples) {
                    $escaped['otherhost'][] = $url;
                }
            }
            continue;
        }
        if (preg_match('~(^|&|&amp;)id=\d+~i', $parts[2])) {
            $stats['idnotfirst']++;
            $key = 'idnotfirst';
        } else {
            $stats['otherscript']++;
            $key = 'otherscript';
        }
        if (!isset($escaped[$key])) {
            $escaped[$key] = [];
        }
        if (count($escaped[$key]) < $maxexamples) {
            $escaped[$key][] = $url;
        }
    }
    if ($found) {
        $withlinks++;
    }
}

local_resourcelinkfix_line(get_string('cli_htmlfiles', 'local_resourcelinkfix'), count($htmlfiles), 0);
local_resourcelinkfix_line(get_string('cli_htmlwithlinks', 'local_resourcelinkfix'), $withlinks, count($htmlfiles));
echo "\n";
cli_heading(get_string('cli_htmllinks', 'local_resourcelinkfix'));
local_resourcelinkfix_line(get_string('cli_total', 'local_resourcelinkfix'), $stats['total'], 0);
local_resourcelinkfix_line(get_string('cli_covered', 'local_resourcelinkfix'), $stats['covered'], $stats['total']);
local_resourcelinkfix_line(get_string('cli_otherhost', 'local_resourcelinkfix'), $stats['otherhost'], $stats['total']);
local_resourcelinkfix_line(get_string('cli_idnotfirst', 'local_resourcelinkfix'), $stats['idnotfirst'], $stats['total']);
local_resourcelinkfix_line(get_string('cli_otherscript', 'local_resourcelinkfix'), $stats['otherscript'], $stats['total']);

if ($scripts) {
    echo "\n  " . get_string('cli_topscripts', 'local_resourcelinkfix') . "\n";
    arsort($scripts);
    $shown = 0;
    foreach ($scripts as $name => $count) {
        printf("    %7d  %s\n", $count, $name);
        if (++$shown >= 10) {
            break;
        }
    }
}
if ($hosts) {
    echo "\n  " . get_string('cli_hosts', 'local_resourcelinkfix') . "\n";
    arsort($hosts);
    foreach ($hosts as $name => $count) {
        printf("    %7d  %s\n", $count, $name);
    }
}
foreach ($escaped as $key => $examples) {
    echo "\n  " . get_string('cli_examplesof', 'local_resourcelinkfix', get_string('cli_' . $key, 'local_resourcelinkfix')) . "\n";
    foreach ($examples as $url) {
        echo '    ' . substr($url, 0, 110) . "\n";
    }
}

// JS files.
if ($withjs) {
    echo "\n";
    cli_heading(get_string('cli_jsheading', 'local_resourcelinkfix'));

    $jsrecords = local_resourcelinkfix_fetch_files('%.js', $courseid);
    $jsfiles = local_resourcelinkfix_read_contents($jsrecords, $seenjs, $missing);

    // Literal: the whole URL, with a numeric id, in quotes.
    $literal = '~["\'][^"\']*(?:mod/[a-z0-9_]+/(?:view|index|complete)|course/view)\.php\?id=\d+[^"\']*["\']~i';
    // Built: after 'id=' comes concatenation, a template or a variable.
    $built = '~(?:mod/[a-z0-9_]+/(?:view|index|complete)|course/view)\.php\?id=(?:["\']\s*[+.]|\$\{|[a-z_$])~i';
    $any = '~(?:mod/[a-z0-9_]+/(?:view|index|complete)|course/view)\.php\?id=~i';

    $jswith = 0;
    $jstotal = 0;
    $jsliteral = 0;
    $jsbuilt = 0;
    $jssamples = [];

    foreach ($jsfiles as $content) {
        $occurrences = preg_match_all($any, $content, $ignored);
        if (!$occurrences) {
            continue;
        }
        $jswith++;
        $jstotal += $occurrences;
        $jsliteral += preg_match_all($literal, $content, $ignored);
        $jsbuilt += preg_match_all($built, $content, $ignored);
        if (
            count($jssamples) < $maxexamples
                && preg_match(
                    '~[^\s"\']*(?:mod/[a-z0-9_]+/(?:view|index|complete)|course/view)\.php\?id=\d+~i',
                    $content,
                    $sample
                )
        ) {
            $jssamples[] = $sample[0];
        }
    }

    local_resourcelinkfix_line(get_string('cli_jsfiles', 'local_resourcelinkfix'), count($jsfiles), 0);
    local_resourcelinkfix_line(get_string('cli_jswithlinks', 'local_resourcelinkfix'), $jswith, count($jsfiles));
    echo "\n";
    local_resourcelinkfix_line(get_string('cli_jsoccurrences', 'local_resourcelinkfix'), $jstotal, 0);
    local_resourcelinkfix_line(get_string('cli_jsliteral', 'local_resourcelinkfix'), $jsliteral, $jstotal);
    local_resourcelinkfix_line(get_string('cli_jsbuilt', 'local_resourcelinkfix'), $jsbuilt, $jstotal);
    local_resourcelinkfix_line(
        get_string('cli_jsunclassified', 'local_resourcelinkfix'),
        max(0, $jstotal - $jsliteral - $jsbuilt),
        $jstotal
    );

    if ($jssamples) {
        echo "\n  " . get_string('cli_examples', 'local_resourcelinkfix') . "\n";
        foreach ($jssamples as $sample) {
            echo '    ' . substr($sample, 0, 110) . "\n";
        }
    }

    echo "\n" . get_string('cli_jsnote', 'local_resourcelinkfix') . "\n";
}

if ($missing) {
    echo "\n";
    cli_problem(get_string('cli_missing', 'local_resourcelinkfix', $missing));
}

echo "\n";
