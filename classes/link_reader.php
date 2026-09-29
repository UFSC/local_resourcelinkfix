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
 * Finds links and reads the text glued before each one.
 *
 * Shared by the restore plugin and the measuring tool, so that what the tool
 * reports as reached is exactly what the plugin rewrites.
 *
 * Two steps. A pattern finds the path and the id - 'mod/page/view.php?id=N' -
 * which is fast and linear. Then the code reads back from the path to the
 * start of the run of text without spaces, quotes or angle brackets (or to the
 * end of the previous link): that is the prefix, and it decides whether the
 * link is relative, from the source site, or to be left alone.
 *
 * @package    local_resourcelinkfix
 * @copyright  2026 UFSC
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class link_reader {
    /** Characters that end a run: whitespace, quotes and angle brackets. */
    const DELIMITERS = " \t\n\r\f\v\"'<>";

    /** Whitespace, the only delimiter a URL can be split by. */
    const WHITESPACE = " \t\n\r\f\v";

    /** @var string Source wwwroot without its scheme ('site/moodle'); empty when unknown. */
    protected $source;

    /**
     * Constructor.
     *
     * @param string $sourcewwwroot Wwwroot of the site where the backup was made; empty when unknown.
     */
    public function __construct($sourcewwwroot) {
        $this->source = self::strip_scheme(rtrim($sourcewwwroot, '/'));
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
     * @param string $wwwroot
     * @return string
     */
    public static function strip_scheme($wwwroot) {
        return preg_replace('~^[a-z][a-z0-9+.\-]*://~i', '', $wwwroot);
    }

    /**
     * Finds the links in the content.
     *
     * Each link is an array with: 'start' and 'end' (offsets of prefix start
     * and link end), 'prefix', 'pathid' (path up to '?id='), 'path', 'script',
     * 'id', and 'source': null for a relative link, false to leave it alone,
     * or [lead, whether the URL has a scheme] for the source site.
     *
     * @param string $content
     * @return array|null Null when PCRE aborts.
     */
    public function find($content) {
        $count = preg_match_all(self::get_pattern(), $content, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        if ($count === false) {
            return null;
        }
        $links = [];
        $low = 0;
        foreach ($matches as $m) {
            $pathstart = $m[0][1];
            $before = substr($content, $low, $pathstart - $low);
            $prefixlength = strcspn(strrev($before), self::DELIMITERS);
            $start = $pathstart - $prefixlength;
            $prefix = (string)substr($content, $start, $prefixlength);
            $token = $this->token_before((string)substr($content, $low, $start - $low));
            $end = $pathstart + strlen($m[0][0]);
            $links[] = [
                'start' => $start,
                'end' => $end,
                'prefix' => $prefix,
                'pathid' => $m[1][0],
                'path' => $m[2][0],
                'script' => $m[3][0],
                'id' => (int)$m[4][0],
                'source' => $this->read_prefix($prefix, $token),
            ];
            $low = $end;
        }
        return $links;
    }

    /**
     * The run glued before the whitespace that precedes a prefix.
     *
     * Browsers drop line breaks and tabs from a URL, and text pasted from a
     * PDF splits addresses with spaces. When the prefix follows whitespace,
     * the piece right before it may be the start of the same address.
     *
     * @param string $text Text between the previous link (or the start) and the prefix.
     * @return string Empty when the prefix does not follow whitespace.
     */
    protected function token_before($text) {
        if ($text === '' || strpos(self::WHITESPACE, substr($text, -1)) === false) {
            return '';
        }
        $text = rtrim($text, self::WHITESPACE);
        $length = strcspn(strrev($text), self::DELIMITERS);
        return $length ? substr($text, -$length) : '';
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
     * - a segment that looks like a domain, or whitespace right after a piece
     *   that looks like part of an address: a URL split by hyphenation or by
     *   a line break, whose start is out of the prefix;
     * - an absolute URL whose base is not exactly the source's wwwroot.
     *
     * @param string $prefix
     * @param string $token The piece before the whitespace that precedes the prefix, if any.
     * @return array|false|null Null for a relative path; false to leave the
     *                          link alone; for the source site, [lead, whether
     *                          the URL has a scheme].
     */
    public function read_prefix($prefix, $token = '') {
        $marks = substr_count($prefix, '//');
        if ($marks === 0) {
            return $this->read_relative_prefix($prefix, $token);
        }
        if ($marks > 1) {
            return false;
        }
        return $this->read_absolute_prefix($prefix);
    }

    /**
     * Reads a prefix without an authority mark.
     *
     * @param string $prefix
     * @param string $token
     * @return false|null Null for a relative path; false when it may be part
     *                    of an address whose start is out of sight.
     */
    protected function read_relative_prefix($prefix, $token) {
        if ($this->looks_like_address_piece($token)) {
            return false;
        }
        // Credentials: an '@' followed by a host. An '@' elsewhere ('@media'
        // in CSS) says nothing about the address.
        if (preg_match('~@[a-z0-9.\-\[\]:]+/~i', $prefix)) {
            return false;
        }
        foreach (explode('/', $prefix) as $segment) {
            if (preg_match('~\.[a-z]{2,}(?::\d+)?$~i', $segment)) {
                return false;
            }
        }
        return null;
    }

    /**
     * Does the piece before a whitespace look like part of an address?
     *
     * Running text ('see', 'then') does not. An authority mark, a dot
     * followed by letters, or a trailing '/', ':', '.' or '-' does.
     *
     * @param string $token
     * @return bool
     */
    protected function looks_like_address_piece($token) {
        if ($token === '') {
            return false;
        }
        if (strpos($token, '//') !== false || strpos($token, '@') !== false) {
            return true;
        }
        return (bool)preg_match('~\.[a-z]|[/:.\-]$~i', $token);
    }

    /**
     * Reads a prefix with a single authority mark.
     *
     * @param string $prefix
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
        return [substr($prefix, 0, $start), $start < $pos];
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
     * @param string $prefix
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
}
