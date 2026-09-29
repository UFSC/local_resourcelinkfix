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
 * Finds activity and course links in content and decides which are safe to rewrite.
 *
 * @package    local_resourcelinkfix
 * @copyright  2026 UFSC
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_resourcelinkfix;

/**
 * Finds links and decides which are safe to rewrite.
 *
 * Shared by the restore plugin and the measuring tool, so that what the tool
 * reports as reached is exactly what the plugin rewrites.
 *
 * Two steps. A pattern finds the path and the id - 'mod/page/view.php?id=N' -
 * which is fast and linear. Then the code reads back from the path to the
 * start of the run of text without spaces, quotes or angle brackets (or to the
 * end of the previous link): that is the prefix.
 *
 * - An absolute link (the prefix holds '//') is rewritten only when its base
 *   is exactly the source's wwwroot.
 * - A relative link is rewritten only where a link value starts: right after
 *   'href=', 'src=' or a CSS 'url(' - and, in a .js file, a string literal
 *   that starts a value. Anywhere else it is left alone.
 *
 * DO NOT go back to reading the text before a relative path to guess whether
 * it is an address. That was tried through five review rounds, and each one
 * found another form a browser reads as another site: hyphenation, line
 * breaks, entities, escapes, a quote inside the value, JS concatenation,
 * <base href>. DESIGN.md records the decision and how to extend it safely.
 *
 * @package    local_resourcelinkfix
 * @copyright  2026 UFSC
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class link_reader {
    /** Reading an HTML file. */
    const MODE_HTML = 'html';

    /** Reading a .js file. */
    const MODE_JS = 'js';

    /** Characters that end a run: spaces, quotes and angle brackets. */
    const DELIMITERS = " \f\v\"'<>";

    /** Characters browsers drop from inside a URL. */
    const DROPPED = "\t\n\r";

    /** Characters of a plain relative path: segments and slashes. */
    const PATHCHARS = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_.~%-/';

    /** @var string Source wwwroot without its scheme ('site/moodle'); empty when unknown. */
    protected $source;

    /** @var string MODE_HTML or MODE_JS. */
    protected $mode;

    /** @var bool Whether the content being read declares a <base href>. */
    protected $hasbase = false;

    /**
     * Constructor.
     *
     * @param string $sourcewwwroot Wwwroot of the site where the backup was made; empty when unknown.
     * @param string $mode MODE_HTML or MODE_JS.
     */
    public function __construct($sourcewwwroot, $mode = self::MODE_HTML) {
        $this->source = self::strip_scheme(rtrim($sourcewwwroot, '/'));
        $this->mode = $mode;
    }

    /**
     * The pattern that recognises the path and the id of an activity or course link.
     *
     * Groups: 1 path up to '?id=', 2 path, 3 script, 4 id.
     *
     * @return string
     */
    public static function get_pattern() {
        return '~(?<![a-z0-9_])((mod/[a-z0-9_]+/(view|index|complete)|course/view)\.php\?id=)(\d+)(?!\d)~i';
    }

    /**
     * A wwwroot without its scheme and authority mark: 'site/moodle'.
     *
     * @param string $wwwroot A wwwroot, with or without a scheme.
     * @return string
     */
    public static function strip_scheme($wwwroot) {
        return preg_replace('~^[a-z][a-z0-9+.\-]*://~i', '', $wwwroot);
    }

    /**
     * Visits the links in the content, one at a time.
     *
     * Each link is an array with: 'start' and 'end' (offsets of prefix start
     * and link end), 'prefix', 'pathid' (path up to '?id='), 'path', 'script',
     * 'id', and 'source': null for a relative link, false to leave it alone,
     * or [lead, whether the URL has a scheme] for the source site.
     *
     * One link at a time, and none kept: memory follows the size of the
     * content, not the number of links. Each search starts where the previous
     * link ended, so the content is read once.
     *
     * @param string $content The content to read.
     * @param callable $visit Called with each link, in order.
     * @return bool False when PCRE aborts.
     */
    public function each_link($content, $visit) {
        // With a <base href>, relative links resolve against another address.
        $this->hasbase = $this->mode === self::MODE_HTML && preg_match('~<base\b[^>]*\bhref~i', $content);
        $pattern = self::get_pattern();
        $offset = 0;
        while (true) {
            $found = preg_match($pattern, $content, $m, PREG_OFFSET_CAPTURE, $offset);
            if ($found === false) {
                return false;
            }
            if ($found === 0) {
                return true;
            }
            $pathstart = $m[0][1];
            $prefixlength = strcspn(strrev((string)substr($content, $offset, $pathstart - $offset)), self::DELIMITERS);
            $start = $pathstart - $prefixlength;
            $end = $pathstart + strlen($m[0][0]);
            $prefix = (string)substr($content, $start, $prefixlength);
            $visit([
                'start' => $start,
                'end' => $end,
                'prefix' => $prefix,
                'pathid' => $m[1][0],
                'path' => $m[2][0],
                'script' => $m[3][0],
                'id' => (int)$m[4][0],
                'source' => $this->read_prefix($prefix, $this->opens_value($content, $start)),
            ]);
            $offset = $end;
        }
    }

    /**
     * All the links in the content, for short content and tests.
     *
     * @param string $content The content to read.
     * @return array|null The links, as each_link() describes them; null when PCRE aborts.
     */
    public function find($content) {
        $links = [];
        $ok = $this->each_link($content, function ($link) use (&$links) {
            $links[] = $link;
        });
        return $ok ? $links : null;
    }

    /**
     * Does a link value start where the run starts?
     *
     * True at the start of the text, and right after the quote (or spaces)
     * that open the value of 'href=', 'src=' or a CSS 'url('. In a .js file,
     * also right after a quote that opens a string starting a value: after
     * '=', '(', ',', ':' or '['. Never after '+': that string continues
     * another one.
     *
     * @param string $content The content being read.
     * @param int $start Offset where the run starts.
     * @return bool
     */
    protected function opens_value($content, $start) {
        if ($start === 0) {
            return true;
        }
        $window = substr($content, max(0, $start - 80), min(80, $start));
        $opener = '(?:\b(?:href|src)\s*=|url\()(?:\s*["\']|\s+)';
        if ($this->mode === self::MODE_JS) {
            $opener .= '|[=(,:\[]\s*["\']';
        }
        return (bool)preg_match('~(?:' . $opener . ')$~i', $window);
    }

    /**
     * Text as a browser reads it inside a URL.
     *
     * HTML entities decoded ('&#47;', '&sol;', '&Tab;'), then line breaks and
     * tabs dropped - the order a browser follows.
     *
     * Backslashes stay: a backslash may be a path separator or an escape
     * ('\x2f' in JS, '\00002f' in CSS), and any prefix holding one is left
     * alone.
     *
     * @param string $text Text from the content.
     * @return string
     */
    public static function normalize($text) {
        if (strpos($text, '&') !== false) {
            $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        return str_replace(str_split(self::DROPPED), '', $text);
    }

    /**
     * Reads the prefix glued before the path.
     *
     * The link is absolute when the prefix has an authority mark ('//'). What
     * comes before its scheme - 'url(', 'href=' - is the lead, kept as it is.
     * The link is left alone, by returning false, whenever the prefix cannot
     * be read without guessing:
     * - more than one '//': two URLs glued together, or one URL carried in
     *   another's parameter, and there is no telling which the path belongs to;
     * - a scheme other than http or https, or credentials;
     * - a relative path that is not where a link value starts, or is not a
     *   plain path (see read_relative_prefix());
     * - an absolute URL whose base is not exactly the source's wwwroot, or
     *   whose lead is not plain.
     *
     * The prefix is read the way a browser reads it (see normalize()). A
     * prefix that changes when normalised is only trusted as a relative path:
     * rewriting it as the source's would have to decide what to do with what
     * was normalised.
     *
     * @param string $prefix The text glued before the path.
     * @param bool $opener Whether a link value starts where the prefix starts.
     * @return array|false|null Null for a relative path; false to leave the
     *                          link alone; for the source site, [lead, whether
     *                          the URL has a scheme].
     */
    public function read_prefix($prefix, $opener = true) {
        $clean = self::normalize($prefix);
        $marks = substr_count($clean, '//');
        if ($marks === 0) {
            return $this->read_relative_prefix($clean, $opener);
        }
        if ($marks > 1 || $clean !== $prefix) {
            return false;
        }
        return $this->read_absolute_prefix($prefix);
    }

    /**
     * Reads a prefix without an authority mark.
     *
     * Relative only when it is a plain path - the longest tail of path
     * characters - and a link value starts right before that path: either the
     * run starts at a value opener and the path is the whole prefix, or the
     * prefix itself opens the value ('href=', '...url(').
     *
     * @param string $prefix The prefix, normalised.
     * @param bool $opener Whether a link value starts where the prefix starts.
     * @return false|null Null for a relative path; false to leave the link alone.
     */
    protected function read_relative_prefix($prefix, $opener) {
        if ($this->hasbase || strpos($prefix, '\\') !== false) {
            return false;
        }
        $pathlength = strspn(strrev($prefix), self::PATHCHARS);
        $lead = (string)substr($prefix, 0, strlen($prefix) - $pathlength);
        if ($lead === '' ? !$opener : !$this->lead_opens_value($lead)) {
            return false;
        }
        $path = (string)substr($prefix, strlen($prefix) - $pathlength);
        foreach (explode('/', $path) as $segment) {
            if (preg_match('~\.[a-z]{2,}(?::\d+)?$~i', $segment)) {
                return false;
            }
        }
        return null;
    }

    /**
     * Does the lead inside the run open a link value - 'href=', '...url('?
     *
     * @param string $lead The text before the path, normalised.
     * @return bool
     */
    protected function lead_opens_value($lead) {
        return $this->is_plain_lead($lead) && preg_match('~\b(?:href|src)=$|url\($~i', $lead);
    }

    /**
     * Is the text before a path or a URL plain?
     *
     * A slash, a scheme, a numeric character reference or a backslash there
     * means the path or URL may continue something else: a folder, another
     * address, an encoded or escaped one.
     *
     * @param string $lead The text before the path or URL, normalised.
     * @return bool
     */
    protected function is_plain_lead($lead) {
        return strpos($lead, '/') === false
            && strpos($lead, '\\') === false
            && strpos($lead, '&#') === false
            && !preg_match('~https?:~i', $lead);
    }

    /**
     * Reads a prefix with a single authority mark.
     *
     * @param string $prefix The prefix.
     * @return array|false For the source site, [lead, whether the URL has a
     *                     scheme]; false otherwise.
     */
    protected function read_absolute_prefix($prefix) {
        if ($this->source === '') {
            return false;
        }
        $pos = strpos($prefix, '//');
        $start = $this->url_start($prefix, $pos);
        if ($start === false) {
            return false;
        }
        if (!$this->is_source_base(substr($prefix, $pos + 2))) {
            return false;
        }
        // The URL must start the address, not be carried in another's parameter.
        $lead = substr($prefix, 0, $start);
        if (!$this->is_plain_lead($lead)) {
            return false;
        }
        return [$lead, $start < $pos];
    }

    /**
     * Is the base exactly the source's wwwroot?
     *
     * 'site/' is not 'site/other/', nor 'user@site/'. The host is compared
     * ignoring case; the path is not, because the server tells them apart.
     *
     * @param string $base Base without scheme, ending in '/'.
     * @return bool
     */
    protected function is_source_base($base) {
        $expected = $this->source . '/';
        if (strlen($base) !== strlen($expected)) {
            return false;
        }
        $slash = strpos($expected, '/');
        return strcasecmp(substr($base, 0, $slash), substr($expected, 0, $slash)) === 0
            && substr($base, $slash) === substr($expected, $slash);
    }

    /**
     * Where the URL starts: at its scheme, or at the '//' when it has none.
     *
     * @param string $prefix The prefix.
     * @param int $pos Position of the '//'.
     * @return int|false False for a scheme other than http or https.
     */
    protected function url_start($prefix, $pos) {
        if ($pos === 0 || $prefix[$pos - 1] !== ':') {
            return $pos;
        }
        $start = $pos - 1;
        while ($start > 0 && preg_match('~[a-z0-9+.\-]~i', $prefix[$start - 1])) {
            $start--;
        }
        if (!preg_match('~^https?$~i', substr($prefix, $start, $pos - 1 - $start))) {
            return false;
        }
        return $start;
    }

    /**
     * The end of a link as found, on one line, for a report.
     *
     * The prefix may be megabytes long - it runs back to the start of the run
     * - so the excerpt keeps its end, where the link is.
     *
     * @param array $link A link, as each_link() describes it.
     * @param int $length Maximum length of the excerpt.
     * @return string
     */
    public static function excerpt($link, $length = 110) {
        $tail = substr($link['prefix'], -2 * $length) . $link['pathid'] . $link['id'];
        $tail = preg_replace('~\s+~', ' ', $tail);
        if (strlen($tail) <= $length) {
            return $tail;
        }
        return '...' . substr($tail, -($length - 3));
    }

    /**
     * The host of a link's own URL, for a report.
     *
     * @param array $link A link, as each_link() describes it.
     * @return string|false|null Scheme and host for the source site; null for
     *                           a relative link; false for a link left alone,
     *                           whose host is not known for sure.
     */
    public static function host_of($link) {
        if (!is_array($link['source'])) {
            return $link['source'];
        }
        $url = substr($link['prefix'], strlen($link['source'][0]));
        return preg_match('~^((?:[a-z][a-z0-9+.\-]*:)?//[^/]+)~i', $url, $m) ? strtolower($m[1]) : false;
    }
}
