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
     * or the next test passes by mistake. Measured in this environment: with
     * backtrack_limit=100 the pattern still completes; from 30 down it aborts.
     */
    public function test_chosen_limit_really_aborts_pcre(): void {
        ini_set('pcre.backtrack_limit', '10');

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
        ini_set('pcre.backtrack_limit', '10');

        $plugin = $this->plugin();
        // Neither setExpectedException() (removed in PHPUnit 6) nor expectException() (only from 5.2):
        // try/catch runs from Moodle 3.0 (PHPUnit 4.8) to 3.8 (PHPUnit 7.5).
        try {
            $plugin->rewrite_file_for_test($this->heavy_content());
            $this->fail('rewrite_file() wrote with PCRE aborted; it should throw moodle_exception');
        } catch (moodle_exception $e) {
            $this->assertInstanceOf('moodle_exception', $e);
        }
    }

    /**
     * With PCRE aborted, the guard says no: without a mask there is no check.
     */
    public function test_guard_says_no_when_it_cannot_check(): void {
        ini_set('pcre.backtrack_limit', '10');

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
