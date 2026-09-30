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
 * Restore plugin for local_resourcelinkfix.
 *
 * During restore, rewrites the links to activities inside the HTML files of
 * resources (mod_resource), using the restore's own id mapping.
 * Compatible with PHP 5.4+ / Moodle 3.0+.
 *
 * @package    local_resourcelinkfix
 * @copyright  2026 UFSC
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Rewrites the links to activities in mod_resource HTML during restore.
 *
 * @package    local_resourcelinkfix
 * @copyright  2026 UFSC
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class restore_local_resourcelinkfix_plugin extends restore_local_plugin {
    /**
     * Old => new cmid map per restore, shared between the plugin instances
     * (there is one per restored activity).
     *
     * @var array restoreid => array
     */
    protected static $cmmaps = [];

    /** @var array Old cmid => new cmid of the current restore. */
    protected $cmmap = [];

    /** @var int Course id on the source site. */
    protected $oldcourseid = 0;

    /** @var int Id of the course restored here. */
    protected $newcourseid = 0;

    /** @var string Wwwroot of the site where the backup was made. */
    protected $oldwwwroot = '';

    /** @var string Wwwroot of this site. */
    protected $newwwwroot = '';

    /** @var bool Simulation mode: measures and logs, does not write. */
    protected $dryrun = false;

    /** @var int Links replaced in the current file. */
    protected $linkcount = 0;

    /** @var bool Also rewrite .js files. */
    protected $rewritejs = false;

    /**
     * Hooks into the /module path, not /course.
     *
     * restore_course_task::build() only adds restore_course_structure_step
     * (where the /course path lives) when the target is a new course or when
     * 'overwrite_conf' is on. So an after_restore_course() never runs when
     * restoring into an existing course or importing activities.
     * restore_module_structure_step, on the other hand, is unconditional
     * (restore_activity_task::build()), as long as 'activities' is on.
     *
     * The path does not exist in module.xml: what matters is registering the
     * processing object, because launch_after_restore_methods() iterates over
     * the step's path elements.
     *
     * @return restore_path_element[]
     */
    protected function define_module_plugin_structure() {
        return [
            new restore_path_element(
                'local_resourcelinkfix_module',
                $this->get_pathfor('/resourcelinkfix')
            ),
        ];
    }

    /**
     * Never called (the path does not exist in the backup), but it must exist:
     * restore_path_element requires the method on the processing object.
     *
     * @param array|stdClass $data
     */
    public function process_local_resourcelinkfix_module($data) {
        // Nothing to restore.
    }

    /**
     * Run by the 'executing_after_restore' step of restore_final_task, once
     * per restored activity, after all activities and before
     * 'drop_and_clean_temp_stuff'. backup_ids_temp is still available.
     */
    public function after_restore_module() {
        global $CFG;

        if ($this->task->get_modulename() !== 'resource') {
            return;
        }

        // Disabled: restore behaves as if the plugin did not exist.
        // get_config() returns false when the setting was never saved.
        // Then the settings.php default applies, which is on.
        $enabled = get_config('local_resourcelinkfix', 'enabled');
        if ($enabled !== false && !$enabled) {
            return;
        }
        $this->dryrun = (bool)get_config('local_resourcelinkfix', 'dryrun');
        $this->rewritejs = (bool)get_config('local_resourcelinkfix', 'rewritejs');

        $this->newcourseid = (int)$this->task->get_courseid();
        $this->oldcourseid = (int)$this->task->get_old_courseid();

        // Backup from another site: absolute links carry that site's wwwroot.
        // Core replaces it in text fields (restore_decode_processor), but
        // never in file content.
        $info = $this->task->get_info();
        $this->oldwwwroot = isset($info->original_wwwroot)
            ? rtrim($info->original_wwwroot, '/') : '';
        $this->newwwwroot = rtrim($CFG->wwwroot, '/');

        $this->cmmap = $this->get_cmmap();
        if (empty($this->cmmap)) {
            return;
        }

        $cmid = (int)$this->task->get_moduleid();
        $context = context_module::instance($cmid, IGNORE_MISSING);
        if (!$context) {
            return;
        }

        $fs = get_file_storage();
        $files = $fs->get_area_files($context->id, 'mod_resource', 'content', 0, 'sortorder', false);
        foreach ($files as $file) {
            if (!$this->should_process_file($file->get_filename())) {
                continue;
            }
            // Alias or external file: the content belongs elsewhere.
            if ($file->is_external_file()) {
                continue;
            }
            try {
                $this->rewrite_file($fs, $file);
            } catch (Exception $e) {
                // One problematic file must not abort the whole restore.
                $this->task->get_logger()->process(
                    get_string('errorrewritefailed', 'local_resourcelinkfix', (object)[
                        'file' => $file->get_filepath() . $file->get_filename(),
                        'cmid' => $cmid,
                        'error' => $e->getMessage(),
                    ]),
                    backup::LOG_WARNING
                );
            }
        }
    }

    /**
     * Decides whether a file in the content area is rewritten.
     *
     * HTML always. .js files only when the setting is on: .js is code, and a
     * mistake there breaks the resource's whole navigation, not one link.
     *
     * @param string $filename Name of a file in the content area.
     * @return bool
     */
    protected function should_process_file($filename) {
        if (preg_match('/\.html?$/i', $filename)) {
            return true;
        }
        return $this->rewritejs && (bool)preg_match('/\.js$/i', $filename);
    }

    /**
     * Old => new cmid map of the current restore, read only once.
     *
     * newitemid is NOT NULL DEFAULT 0: modules that were not fully restored
     * keep 0. Mapping them would rewrite the link to '?id=0', trading a stale
     * link for a broken one.
     *
     * @return array
     */
    protected function get_cmmap() {
        global $DB;

        $restoreid = $this->task->get_restoreid();
        if (isset(self::$cmmaps[$restoreid])) {
            return self::$cmmaps[$restoreid];
        }

        $records = $DB->get_records_menu(
            'backup_ids_temp',
            ['backupid' => $restoreid, 'itemname' => 'course_module'],
            '',
            'itemid, newitemid'
        );
        $map = [];
        foreach ($records as $oldid => $newid) {
            if ($newid > 0) {
                $map[(int)$oldid] = (int)$newid;
            }
        }
        self::$cmmaps[$restoreid] = $map;
        return $map;
    }

    /**
     * The link reader for the current restore.
     *
     * @param string $mode 'html', or 'js' for a .js file.
     * @return \local_resourcelinkfix\link_reader
     */
    protected function reader($mode = \local_resourcelinkfix\link_reader::MODE_HTML) {
        return new \local_resourcelinkfix\link_reader($this->oldwwwroot, $mode);
    }

    /**
     * How a file is read: as HTML, or as a .js file.
     *
     * @param string $filename Name of a file in the content area.
     * @return string 'html' or 'js'.
     */
    protected function mode_of($filename) {
        return preg_match('/\.js$/i', $filename)
            ? \local_resourcelinkfix\link_reader::MODE_JS
            : \local_resourcelinkfix\link_reader::MODE_HTML;
    }

    /**
     * Safety guard: does the new content differ from the old ONLY in the links?
     *
     * Replaces each link with a fixed marker in both texts and compares what
     * remains. If the rest is not identical, something outside the links
     * changed - lost text, a truncated file, a regex that swallowed too much -
     * and rewriting that file is abandoned.
     *
     * The alternative would be to trust the regex. A null return from PCRE or
     * a pattern that matches more than it should overwrites teaching material
     * without a trace, and the original is already gone.
     *
     * @param string $old Content before the rewrite.
     * @param string $new Content after the rewrite.
     * @param string $mode 'html', or 'js' for a .js file.
     * @return bool False also when the check could not be made.
     */
    protected function only_links_changed($old, $new, $mode = \local_resourcelinkfix\link_reader::MODE_HTML) {
        $rootpattern = $this->root_pattern();
        $maskedold = $this->mask_links($old, $rootpattern, $mode);
        $maskednew = $this->mask_links($new, $rootpattern, $mode);

        // Without a reliable mask there is no check: say no, to be safe.
        if ($maskedold === null || $maskednew === null) {
            return false;
        }
        return $maskedold === $maskednew;
    }

    /**
     * The pattern of the source's or this site's wwwroot at the end of a prefix.
     *
     * @return string|null Null when neither is known.
     */
    protected function root_pattern() {
        $roots = [];
        foreach ([$this->oldwwwroot, $this->newwwwroot] as $root) {
            $root = \local_resourcelinkfix\link_reader::strip_scheme(rtrim($root, '/'));
            if ($root !== '') {
                $roots[] = $root;
            }
        }
        // Longest first: one wwwroot may extend the other ('site' and 'site/moodle').
        usort($roots, function ($a, $b) {
            return strlen($b) - strlen($a);
        });
        $quoted = array_map(function ($root) {
            return preg_quote($root, '~');
        }, $roots);
        return $quoted ? '~(?:https?:)?//(?:' . implode('|', $quoted) . ')/$~i' : null;
    }

    /**
     * The content with each link's path and id replaced by a marker.
     *
     * The prefix stays, so text lost there is seen. When it holds a single
     * URL ending in the source's or this site's wwwroot, that wwwroot becomes
     * one token: the rewrite may swap one for the other there, and nothing else.
     *
     * The guard protects the content, not the choice of links: an id changed
     * in a link the plugin should have left alone is not its business.
     *
     * @param string $content The content to mask.
     * @param string|null $rootpattern From root_pattern().
     * @param string $mode 'html', or 'js' for a .js file.
     * @return string|null Null when PCRE aborts.
     */
    protected function mask_links($content, $rootpattern, $mode) {
        $masked = '';
        $pos = 0;
        $ok = $this->reader($mode)->each_link($content, function ($link) use ($content, $rootpattern, &$masked, &$pos) {
            $prefix = $link['prefix'];
            if ($rootpattern !== null && substr_count($prefix, '//') === 1) {
                $prefix = preg_replace($rootpattern, "\x00RLFROOT\x00", $prefix);
            }
            $masked .= substr($content, $pos, $link['start'] - $pos) . $prefix . "\x00RLFLINK\x00";
            $pos = $link['end'];
        });
        return $ok ? $masked . substr($content, $pos) : null;
    }

    /**
     * Writes content to the restore log, in chunks.
     *
     * The restore logger was not built for long text, so the output goes out
     * sliced and labelled. The goal is to allow rebuilding what would have
     * been lost, not to produce a readable diff.
     *
     * @param string $label Label for each line, such as BEFORE or AFTER.
     * @param string $content Content to log.
     */
    protected function log_content($label, $content) {
        // Deliberate cap. At LOG_ERROR the restore logger chain goes through
        // error_log, a file and one INSERT per line in backup_logs - and, with
        // debugdisplay on, echoes on screen. A 4 MB HTML would become
        // thousands of records, twice. What matters is seeing the damage, not
        // archiving the document.
        $limit = 8192;
        $truncated = (strlen($content) > $limit);
        $chunks = str_split(substr($content, 0, $limit), 800);
        $total = count($chunks);
        foreach ($chunks as $i => $chunk) {
            $this->task->get_logger()->process(
                sprintf('local_resourcelinkfix [%s %d/%d] %s', $label, $i + 1, $total, $chunk),
                backup::LOG_ERROR
            );
        }
        if ($truncated) {
            $this->task->get_logger()->process(
                get_string('logtruncated', 'local_resourcelinkfix', (object)[
                    'label' => $label,
                    'limit' => $limit,
                    'size' => strlen($content),
                ]),
                backup::LOG_ERROR
            );
        }
    }

    /**
     * Writes the content back, keeping the file record (id, sortorder,
     * filename, timecreated). replace_file_with() only replaces contenthash,
     * filesize, referencefileid and userid. That is why the temporary file is
     * created with the same userid.
     *
     * @param file_storage $fs
     * @param stored_file $file
     */
    protected function rewrite_file($fs, $file) {
        $old = $file->get_content();
        $this->linkcount = 0;
        $mode = $this->mode_of($file->get_filename());
        $new = $this->rewrite_links($old, $mode);

        // A preg_replace_callback() call returns null when PCRE aborts, without
        // throwing. Treating that as "new content" would write an empty file
        // over the original, and it would be lost. The exception lands in the
        // catch of after_restore_module() and becomes a warning in the restore log.
        if ($new === null) {
            throw new moodle_exception(
                'errorpcre',
                'local_resourcelinkfix',
                '',
                preg_last_error()
            );
        }

        if ($new === $old) {
            return;
        }

        // Guard: nothing but the links may have changed. If something did, the
        // file stays as it is and both contents go to the log, so what would be
        // lost can be seen.
        if (!$this->only_links_changed($old, $new, $mode)) {
            $this->task->get_logger()->process(
                get_string('errorcontentlost', 'local_resourcelinkfix', (object)[
                    'file' => $file->get_filepath() . $file->get_filename(),
                    'cmid' => (int)$this->task->get_moduleid(),
                    'oldsize' => strlen($old),
                    'newsize' => strlen($new),
                ]),
                backup::LOG_ERROR
            );
            $this->log_content('BEFORE', $old);
            $this->log_content('AFTER', $new);
            return;
        }

        $a = (object)[
            'file' => $file->get_filepath() . $file->get_filename(),
            'cmid' => (int)$this->task->get_moduleid(),
            'links' => $this->linkcount,
        ];
        // The simulation is logged as a warning, the level Moodle records by
        // default: whoever turns it on wants to read the result. The rewrite
        // report stays at INFO, so a normal restore does not flood the log.
        $this->task->get_logger()->process(
            get_string(
                $this->dryrun ? 'logdryrun' : 'logrewritten',
                'local_resourcelinkfix',
                $a
            ),
            $this->dryrun ? backup::LOG_WARNING : backup::LOG_INFO
        );

        // Simulation: measured, logged, does not write.
        if ($this->dryrun) {
            return;
        }

        // Leftovers of an interrupted run.
        $existing = $fs->get_file(
            $file->get_contextid(),
            'local_resourcelinkfix',
            'temp',
            $file->get_id(),
            '/',
            'rewrite.tmp'
        );
        if ($existing) {
            $existing->delete();
        }

        $tmpfile = $fs->create_file_from_string([
            'contextid' => $file->get_contextid(),
            'component' => 'local_resourcelinkfix',
            'filearea' => 'temp',
            'itemid' => $file->get_id(),
            'filepath' => '/',
            'filename' => 'rewrite.tmp',
            'userid' => $file->get_userid(),
        ], $new);

        $file->replace_file_with($tmpfile);
        $file->set_timemodified(time());
        $tmpfile->delete();
    }

    /**
     * The pattern that recognises the path and the id of an activity or course link.
     *
     * Public because the measuring tool (cli/measure_links.php) relies on the
     * same recognition. The prefix glued before the path is not in the
     * pattern: \local_resourcelinkfix\link_reader reads it.
     *
     * Groups: 1 path up to '?id=', 2 path, 3 script, 4 id.
     *
     * @return string
     */
    public static function get_link_pattern() {
        return \local_resourcelinkfix\link_reader::get_pattern();
    }

    /**
     * Replaces ids in a single pass, which avoids remapping an id already replaced.
     *
     * Forms handled: mod/xxx/view.php?id=CMID and mod/xxx/complete.php?id=CMID
     * become the new cmid; mod/xxx/index.php?id=COURSE and
     * course/view.php?id=COURSE become the new course. Absolute or relative
     * links. Unmapped ids stay untouched.
     *
     * @param string $content Content of a file.
     * @param string $mode 'html', or 'js' for a .js file.
     * @return string|null Null when PCRE aborts.
     */
    protected function rewrite_links($content, $mode = \local_resourcelinkfix\link_reader::MODE_HTML) {
        $output = '';
        $pos = 0;
        $ok = $this->reader($mode)->each_link($content, function ($link) use ($content, &$output, &$pos) {
            $original = substr($content, $link['start'], $link['end'] - $link['start']);
            $output .= substr($content, $pos, $link['start'] - $pos) . $this->rewrite_link($link, $original);
            $pos = $link['end'];
        });
        return $ok ? $output . substr($content, $pos) : null;
    }

    /**
     * Rewrites one link found by the reader.
     *
     * When in doubt, leave it alone. If there is a hint of an absolute URL and
     * the host cannot be confirmed as the source's, the link stays as it is. A
     * stale link is better than one that points to another Moodle with an id
     * from here and silently opens the wrong activity.
     *
     * In a backup from another site, the old wwwroot of an absolute link is
     * replaced with this site's, but only when the id was also remapped. If
     * the activity was not in the backup, the id is still the other site's:
     * replacing the host would point to this site with a foreign id, which may
     * open another activity. Keeping the old host, the link stays valid on the
     * source site.
     *
     * @param array $link A link, as \local_resourcelinkfix\link_reader::each_link() describes it.
     * @param string $original The link as it is in the content, prefix included.
     * @return string
     */
    protected function rewrite_link($link, $original) {
        if ($link['source'] === false) {
            return $original;
        }
        $new = $this->map_id($link['path'], $link['script'], $link['id']);
        if ($new === $link['id']) {
            return $original;
        }

        $output = $link['prefix'] . $link['pathid'] . $new;
        if ($link['source'] !== null) {
            // Only the URL is replaced - its lead ('url(', for example) is
            // kept. Without a scheme the form is kept too: it inherits the page's.
            $target = $link['source'][1]
                ? $this->newwwwroot
                : '//' . \local_resourcelinkfix\link_reader::strip_scheme($this->newwwwroot);
            $output = $link['source'][0] . $target . '/' . $link['pathid'] . $new;
        }
        $this->linkcount++;
        return $output;
    }

    /**
     * Returns a link's target id.
     *
     * @param string $path Path fragment, such as 'mod/page/view' or 'course/view'.
     * @param string $script Script name, 'view', 'index' or 'complete'.
     * @param int $id Id cited in the link.
     * @return int
     */
    protected function map_id($path, $script, $id) {
        // A module's index.php takes the course id, not a cmid.
        $iscourse = (stripos($path, 'course/') === 0) || (strtolower($script) === 'index');
        if ($iscourse) {
            return ($id === $this->oldcourseid) ? $this->newcourseid : $id;
        }
        return isset($this->cmmap[$id]) ? $this->cmmap[$id] : $id;
    }
}
