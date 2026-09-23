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
                    'local_resourcelinkfix: failed to rewrite ' .
                    $file->get_filepath() . $file->get_filename() .
                    ' (cmid ' . $cmid . '): ' . $e->getMessage(),
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
     * @param string $filename
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
     * Does the prefix glued before the path indicate an absolute URL?
     *
     * Three questions that do not depend on predicting the host's shape, and
     * so let no new URL form slip through.
     *
     * @param string $prefix
     * @return bool
     */
    protected function looks_absolute($prefix) {
        if (strpos($prefix, '://') !== false) {
            return true;
        }
        if (strpos($prefix, '//') === 0) {
            return true;
        }
        if (strpos($prefix, '@') !== false) {
            return true;
        }
        // The last segment looks like a domain ('ple.com/'). Covers the host
        // split by hyphenation, where the '://' was left behind the space and
        // did not enter the prefix. When in doubt, treat it as absolute: the
        // cost of erring this way is only not fixing one link.
        return (bool)preg_match('~\.[a-z]{2,}(?::\d+)?/?$~i', $prefix);
    }

    /**
     * Reduces a prefix to a comparable form: no scheme, no hyphenation
     * spaces, no credentials, no junk before the URL.
     *
     * Comparing the whole BASE, not just the host, is what recognises a
     * Moodle installed in a subfolder - where the wwwroot is 'site/moodle'.
     *
     * @param string $prefix
     * @return string|null Null when there is no readable base.
     */
    protected function normalize_base($prefix) {
        // Hyphenation in text pasted from a PDF: 'moo- dle', 'https:// site'.
        $clean = preg_replace('/-[ \t]+/', '', $prefix);
        $clean = preg_replace('/[ \t]+/', '', $clean);

        // Cuts whatever comes before the URL: 'url(', 'href=', text.
        $pos = strrpos($clean, '://');
        if ($pos !== false) {
            $start = $pos;
            while ($start > 0 && preg_match('~[a-z0-9+.\-]~i', $clean[$start - 1])) {
                $start--;
            }
            $clean = substr($clean, $start);
        }

        // Strips the scheme and the authority mark, if any.
        $clean = preg_replace('~^[a-z][a-z0-9+.\-]*:~i', '', $clean);
        $clean = preg_replace('~^//~', '', $clean);
        // Credentials are not part of the site's identity.
        $clean = preg_replace('~^[^/@]*@~', '', $clean);

        return ($clean === '') ? null : $clean;
    }

    /**
     * Does the prefix point to the site where the backup was made?
     *
     * The comparison is by string start, with the trailing slash included, so
     * that 'origem.org.outro.com/' does not pass for 'origem.org/'.
     *
     * @param string $prefix
     * @return bool
     */
    protected function is_origin_prefix($prefix) {
        if ($this->oldwwwroot === '') {
            return false;
        }

        // Without the authority mark in the prefix there is no telling the URL
        // was read whole: a scheme may come before it, cut off by a
        // hyphenation space. Claiming the source here would produce an address
        // with two schemes glued together.
        if (strpos(preg_replace('/[ \t]+/', '', $prefix), '//') === false) {
            return false;
        }

        $base = $this->normalize_base($prefix);
        $origin = $this->normalize_base($this->oldwwwroot . '/');
        if ($base === null || $origin === null) {
            return false;
        }
        return (strcasecmp(substr($base, 0, strlen($origin)), $origin) === 0);
    }

    /**
     * Replaces the authority (scheme + host) inside the prefix, keeping what
     * comes before it and the intermediate path.
     *
     * @param string $prefix
     * @param string $target New wwwroot.
     * @return string
     */
    protected function replace_authority($prefix, $target) {
        // From the authority to the end of the prefix, tolerating hyphenation.
        $pattern = '~(?:[a-z][a-z0-9+.\-]*:)?[ \t]*//.*$~i';
        if (preg_match($pattern, $prefix)) {
            return preg_replace($pattern, $target . '/', $prefix, 1);
        }
        // Split host with no '//' visible in the prefix: replaces the trailing
        // part that looks like a domain.
        return preg_replace('~[^\s/]*\.[a-z]{2,}(?::\d+)?/?$~i', $target . '/', $prefix, 1);
    }

    /**
     * Is the host that of the site where the backup was made?
     *
     * A scheme-less URL inherits the page's scheme, so the comparison ignores
     * the scheme on both sides in that case.
     *
     * @param string $host
     * @return bool
     */
    protected function is_origin_host($host) {
        if ($this->oldwwwroot === '') {
            return false;
        }
        $origin = $this->oldwwwroot;
        if (strpos($host, '//') === 0) {
            $origin = preg_replace('~^https?:~i', '', $origin);
        }
        return (strcasecmp($host, $origin) === 0);
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
     * @param string $old
     * @param string $new
     * @return bool False also when the check could not be made.
     */
    protected function only_links_changed($old, $new) {
        $marker = "\x00" . 'RLFLINK' . "\x00";
        $pattern = self::get_link_pattern();

        $maskedold = preg_replace($pattern, $marker, $old);
        $maskednew = preg_replace($pattern, $marker, $new);

        // Without a reliable mask there is no check: say no, to be safe.
        if ($maskedold === null || $maskednew === null) {
            return false;
        }
        return $maskedold === $maskednew;
    }

    /**
     * Writes content to the restore log, in chunks.
     *
     * The restore logger was not built for long text, so the output goes out
     * sliced and labelled. The goal is to allow rebuilding what would have
     * been lost, not to produce a readable diff.
     *
     * @param string $label
     * @param string $content
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
                sprintf(
                    'local_resourcelinkfix [%s] ... truncated at %d of %d bytes',
                    $label,
                    $limit,
                    strlen($content)
                ),
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
        $new = $this->rewrite_links($old);

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
        if (!$this->only_links_changed($old, $new)) {
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
     * The pattern that recognises an activity or course link.
     *
     * Public because the measuring tool (cli/measure_links.php) must use
     * exactly the same pattern: if the two diverge, the report measures
     * something other than what the plugin does.
     *
     * Groups: 1 prefix glued before the path (up to 300 characters, no
     * whitespace, quotes or angle brackets; may be empty), 2 path up to
     * '?id=', 3 path, 4 script, 5 id.
     *
     * @return string
     */
    public static function get_link_pattern() {
        // Captures whatever is GLUED before the path, without trying to guess
        // the host's shape. The callback decides: if the prefix hints at an
        // absolute URL and the host cannot be confirmed as the source's,
        // nothing changes.
        //
        // Recognising the host by regex was what failed: every unforeseen
        // form - IPv6, underscore, long path, double slash - was read as a
        // relative path and had its id remapped, pointing to another Moodle
        // with an id from here. The limit of 300 prevents backtracking on a
        // long run without spaces.
        return '~([^\s"\'<>]{0,300})(?<![a-z0-9_])' .
               '((mod/[a-z0-9_]+/(view|index|complete)|course/view)\.php\?id=)(\d+)(?!\d)~i';
    }

    /**
     * Replaces ids in a single pass, which avoids remapping an id already replaced.
     *
     * Forms handled: mod/xxx/view.php?id=CMID and mod/xxx/complete.php?id=CMID
     * become the new cmid; mod/xxx/index.php?id=COURSE and
     * course/view.php?id=COURSE become the new course. Absolute or relative
     * links. Unmapped ids stay untouched.
     *
     * In a backup from another site, the old wwwroot of an absolute link is
     * replaced with this site's, but only when the id was also remapped. If
     * the activity was not in the backup, the id is still the other site's:
     * replacing the host would point to this site with a foreign id, which may
     * open another activity. Keeping the old host, the link stays valid on the
     * source site.
     *
     * @param string $content
     * @return string
     */
    protected function rewrite_links($content) {
        $pattern = self::get_link_pattern();
        // From here on the pattern is the same one only_links_changed() uses.

        return preg_replace_callback($pattern, function ($m) {
            $prefix = $m[1];

            // When in doubt, leave it alone. If there is a hint of an absolute
            // URL and the host cannot be confirmed as the source's, the link
            // stays as it is. A stale link is better than one that points to
            // another Moodle with an id from here and silently opens the wrong
            // activity.
            if ($this->looks_absolute($prefix) && !$this->is_origin_prefix($prefix)) {
                return $m[0];
            }

            $id = (int)$m[5];
            $new = $this->map_id($m[3], $m[4], $id);

            $output = $prefix . $m[2] . $new;
            if ($new !== $id && $this->looks_absolute($prefix)) {
                // Source host, remapped id: the authority becomes this site's
                // too. Only it is replaced - whatever comes before it in the
                // prefix ('url(', for example) is kept.
                $target = $this->newwwwroot;
                if (preg_match('~^[ \t]*//~', $prefix)) {
                    // No scheme: the form is kept, it inherits the page's.
                    $target = preg_replace('~^https?:~i', '', $target);
                }
                $output = $this->replace_authority($prefix, $target) . $m[2] . $new;
            }

            if ($output !== $m[0]) {
                $this->linkcount++;
            }
            return $output;
        }, $content);
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
