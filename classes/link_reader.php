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
 * Line breaks and tabs do not end a run: browsers drop them from a URL, so an
 * address split by them is still one address.
 *
 * @package    local_resourcelinkfix
 * @copyright  2026 UFSC
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class link_reader {
    /** Characters that end a run: spaces, quotes and angle brackets. */
    const DELIMITERS = " \f\v\"'<>";

    /** Spaces: they end a run, and text pasted from a PDF splits addresses with them. */
    const SPACES = " \f\v";

    /** Characters browsers drop from inside a URL. */
    const DROPPED = "\t\n\r";

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
            $reversed = strrev((string)substr($content, $offset, $pathstart - $offset));
            $prefixlength = strcspn($reversed, self::DELIMITERS);
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
                'source' => $this->read_prefix($prefix, $this->token_before($reversed, $prefixlength)),
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
     * The run glued before the spaces that precede a prefix.
     *
     * Text pasted from a PDF splits addresses with spaces. When the prefix
     * follows a space, the piece right before it may be the start of the same
     * address.
     *
     * @param string $reversed Text between the previous link (or the start) and the path, reversed.
     * @param int $prefixlength Length of the prefix at the start of $reversed.
     * @return string Empty when the prefix does not follow a space.
     */
    protected function token_before($reversed, $prefixlength) {
        if ($prefixlength >= strlen($reversed) || strpos(self::SPACES, $reversed[$prefixlength]) === false) {
            return '';
        }
        $tokenstart = $prefixlength + strspn($reversed, self::SPACES, $prefixlength);
        $tokenlength = strcspn($reversed, self::DELIMITERS, $tokenstart);
        return strrev((string)substr($reversed, $tokenstart, $tokenlength));
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
     * - a segment that looks like a domain, a scheme with a single slash, or a
     *   space right after a piece that looks like part of an address: a URL
     *   whose start is out of the prefix;
     * - an absolute URL whose base is not exactly the source's wwwroot.
     *
     * Browsers drop line breaks and tabs from a URL and read backslashes as
     * slashes, so the prefix is read that way. A prefix that holds them is
     * only trusted as a relative path: rewriting it as the source's would have
     * to decide what to do with them.
     *
     * @param string $prefix The text glued before the path.
     * @param string $token The piece before the spaces that precede the prefix, if any.
     * @return array|false|null Null for a relative path; false to leave the
     *                          link alone; for the source site, [lead, whether
     *                          the URL has a scheme].
     */
    public function read_prefix($prefix, $token = '') {
        $clean = strtr(str_replace(str_split(self::DROPPED), '', $prefix), '\\', '/');
        $marks = substr_count($clean, '//');
        if ($marks === 0) {
            return $this->read_relative_prefix($clean, $token);
        }
        if ($marks > 1 || $clean !== $prefix) {
            return false;
        }
        return $this->read_absolute_prefix($prefix);
    }

    /**
     * Reads a prefix without an authority mark.
     *
     * @param string $prefix The prefix, without line breaks or tabs, with slashes for backslashes.
     * @param string $token The piece before the spaces that precede the prefix, if any.
     * @return false|null Null for a relative path; false when it may be part
     *                    of an address whose start is out of sight.
     */
    protected function read_relative_prefix($prefix, $token) {
        if ($this->looks_like_address_piece($token)) {
            return false;
        }
        // A scheme with a single slash: browsers read 'https:/host' as 'https://host'.
        if (preg_match('~(?<![a-z0-9+.\-])https?:/~i', $prefix)) {
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
     * Does the piece before a space look like part of an address?
     *
     * Running text ('see', 'Activity:') and CSS ('background:') do not. An
     * authority mark, a bare 'http:' or 'https:', a domain ('.org/'), or a
     * trailing '/', '.' or '-' do.
     *
     * @param string $token The piece before the spaces.
     * @return bool
     */
    protected function looks_like_address_piece($token) {
        $token = str_replace(str_split(self::DROPPED), '', $token);
        if ($token === '') {
            return false;
        }
        if (strpos($token, '//') !== false || preg_match('~^https?:$~i', $token)) {
            return true;
        }
        return (bool)preg_match('~\.[a-z]{2,}(?::\d+)?(?:/|$)|[/.\-]$~i', $token);
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
