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
 * Tests for behaviour under PCRE limits.
 *
 * @package    local_resourcelinkfix
 * @copyright  2026 UFSC
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_resourcelinkfix;

use advanced_testcase;
use local_resourcelinkfix_testable_plugin;
use moodle_exception;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/resourcelinkfix/tests/fixtures/testable_plugin.php');

/**
 * When PCRE aborts, preg_replace_callback() returns null instead of
 * throwing. Treating null as "new content" would wipe the file.
 *
 * @package    local_resourcelinkfix
 * @copyright  2026 UFSC
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @group      local_resourcelinkfix
 * @covers     \restore_local_resourcelinkfix_plugin
 */
final class pcre_limits_test extends advanced_testcase {
    /** @var string Original value of pcre.backtrack_limit. */
    protected $backtrack;

    /**
     * Saves the original limit, so it does not leak into other tests.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->backtrack = ini_get('pcre.backtrack_limit');
    }

    /**
     * Restores the original limit.
     */
    protected function tearDown(): void {
        ini_set('pcre.backtrack_limit', $this->backtrack);
        parent::tearDown();
    }

    /**
     * Builds a plugin instance with a fixed restore state.
     *
     * @return local_resourcelinkfix_testable_plugin
     */
    protected function plugin() {
        $plugin = new local_resourcelinkfix_testable_plugin();
        $plugin->set_restore_state([
            'cmmap' => [101 => 201],
            'oldcourseid' => 42, 'newcourseid' => 77,
            'oldwwwroot' => 'https://origem.example.org',
            'newwwwroot' => 'https://destino.example.net',
        ]);
        return $plugin;
    }

    /**
     * With PCRE really aborting, the rewrite returns null.
     *
     * This test pins the threshold: the value used must make PCRE abort,
     * or the next test passes by mistake. The pattern only matches the path
     * and the id, and backtracks very little: measured on PHP 5.6, 7.2 and
     * 8.3, with and without the JIT, it completes with backtrack_limit=2 and
     * aborts with 1.
     */
    public function test_chosen_limit_really_aborts_pcre(): void {
        ini_set('pcre.backtrack_limit', '1');

        $plugin = $this->plugin();
        $result = $plugin->rewrite($this->heavy_content());

        $this->assertNull($result, 'the chosen limit did not make PCRE abort; '
            . 'without that the defence tests pass without exercising anything');
        $this->assertSame(PREG_BACKTRACK_LIMIT_ERROR, preg_last_error());
    }

    /**
     * With PCRE aborted, rewrite_file() refuses the file instead of writing it.
     *
     * The null return must not become content: written, it would wipe the file.
     */
    public function test_rewrite_file_refuses_when_pcre_aborts(): void {
        $this->resetAfterTest(true);

        $plugin = $this->plugin();
        // Neither setExpectedException() (removed in PHPUnit 6) nor expectException() (only from 5.2):
        // try/catch runs from Moodle 3.0 (PHPUnit 4.8) to 3.8 (PHPUnit 7.5).
        try {
            $plugin->rewrite_file_for_test($this->heavy_content(), '1');
            $this->fail('rewrite_file() wrote with PCRE aborted; it should throw moodle_exception');
        } catch (moodle_exception $e) {
            $this->assertInstanceOf('moodle_exception', $e);
        }
    }

    /**
     * With PCRE aborted, the guard says no: without a mask there is no check.
     */
    public function test_guard_says_no_when_it_cannot_check(): void {
        ini_set('pcre.backtrack_limit', '1');

        $plugin = $this->plugin();
        $heavy = $this->heavy_content();

        $this->assertFalse(
            $plugin->only_links_differ($heavy, $heavy),
            'without a reliable mask the guard must say no, even for identical texts'
        );
    }

    /**
     * Content that makes PCRE work hard enough to abort under a low limit,
     * and that holds a link to rewrite.
     *
     * @return string
     */
    protected function heavy_content() {
        return '<p>http://x ' . str_repeat('palavra ', 3000) . '</p>'
            . '<a href="../../mod/page/view.php?id=101">link</a>';
    }

    /**
     * A long run without spaces (an image embedded in base64) must not
     * trigger quadratic backtracking.
     *
     * An unanchored greedy prefix took 10.5 s for 30 KB of this same
     * content, against a few milliseconds in the anchored form. Across a
     * collection with many such files, the difference is seconds versus hours.
     */
    public function test_run_without_spaces_does_not_blow_up(): void {
        $plugin = $this->plugin();
        $base64 = str_repeat('AAAABBBBCCCCDDDD1234567890abcdefGHIJKL', 800);
        $content = '<img src="data:image/png;base64,' . $base64 . '">'
            . '<a href="../../mod/page/view.php?id=101">link</a>';

        $start = microtime(true);
        $result = $plugin->rewrite($content);
        $elapsed = microtime(true) - $start;

        $this->assertStringContainsString('view.php?id=201', $result);
        $this->assertLessThan(
            2.0,
            $elapsed,
            'the rewrite took ' . round($elapsed, 2) . ' s: a sign of backtracking'
        );
    }

    /**
     * A run of megabytes without spaces stays linear.
     *
     * The unanchored prefix tried up to 300 characters from every position of
     * the run: 2.3 s for 4 MB with the JIT, 22.6 s without it, and the file
     * goes through the pattern three times. Now only the start of a run is
     * tried.
     */
    public function test_megabyte_run_without_spaces_stays_linear(): void {
        $plugin = $this->plugin();
        $content = '<img src="data:image/png;base64,' . str_repeat('QUJD', 1024 * 1024) . '">'
            . '<a href="../../mod/page/view.php?id=101">link</a>';

        $start = microtime(true);
        $result = $plugin->rewrite($content);
        $elapsed = microtime(true) - $start;

        $this->assertStringContainsString('view.php?id=201', $result);
        $this->assertLessThan(
            0.5,
            $elapsed,
            'the rewrite took ' . round($elapsed, 2) . ' s for 4 MB: a sign of backtracking'
        );
    }

    /**
     * A link at the end of a megabyte run is read, and quickly.
     */
    public function test_link_at_the_end_of_a_megabyte_run(): void {
        $plugin = $this->plugin();
        $content = '<style>.x{background:url(data:image/png;base64,' . str_repeat('QUJD', 1024 * 1024)
            . '),url(../mod/page/view.php?id=101)}</style>';

        $start = microtime(true);
        $result = $plugin->rewrite($content);
        $elapsed = microtime(true) - $start;

        $this->assertStringContainsString('view.php?id=201', substr($result, -60));
        $this->assertLessThan(0.5, $elapsed, 'the rewrite took ' . round($elapsed, 2) . ' s');
    }

    /**
     * Memory does not grow with the number of links.
     *
     * Collecting every match with its offsets, plus an array per link, took
     * over 50 MB for 100,000 links in 4 MB of HTML; running out of memory is a
     * fatal error that no catch stops, and it brings the whole restore down.
     * Links are now read one at a time. The check runs in a separate process
     * with a low memory_limit: within PHPUnit the peak already carries the
     * previous tests, and it cannot be reset before PHP 8.2.
     */
    public function test_memory_does_not_grow_with_the_number_of_links() {
        global $CFG;

        $code = 'define("MOODLE_INTERNAL", 1);'
            . 'require ' . var_export($CFG->dirroot . '/local/resourcelinkfix/classes/link_reader.php', true) . ';'
            . '$c = str_repeat(\'<a href="../mod/page/view.php?id=101">x</a>\', 100000);'
            . '$r = new local_resourcelinkfix\link_reader("https://origem.example.org");'
            . '$n = 0;'
            . '$ok = $r->each_link($c, function ($link) use (&$n) { $n++; });'
            . 'echo $ok ? $n : "abort";';
        $output = [];
        $status = null;
        exec(escapeshellarg(PHP_BINARY) . ' -d memory_limit=48M -r ' . escapeshellarg($code) . ' 2>&1', $output, $status);

        $this->assertSame(0, $status, implode("\n", $output));
        $this->assertSame('100000', trim(implode('', $output)));
    }

    /**
     * Long content with many words after an http:// must neither bring the
     * process down nor hit a limit.
     */
    public function test_long_content_after_scheme_does_not_overflow(): void {
        $plugin = $this->plugin();
        $content = '<p>veja em http://exemplo.example.org ' . str_repeat('palavra ', 9000)
            . '</p><a href="../../mod/page/view.php?id=101">link</a>';

        $result = $plugin->rewrite($content);

        $this->assertNotNull($result);
        $this->assertSame(
            0,
            preg_last_error(),
            'PCRE should not abort: error ' . preg_last_error()
        );
        $this->assertStringContainsString('view.php?id=201', $result);
    }
}
