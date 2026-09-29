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
 * Tests for link rewriting.
 *
 * @package    local_resourcelinkfix
 * @copyright  2026 UFSC
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_resourcelinkfix;

use advanced_testcase;
use local_resourcelinkfix_testable_plugin;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');
require_once($CFG->dirroot . '/local/resourcelinkfix/tests/fixtures/testable_plugin.php');

/**
 * Link rewriting depends only on the restore state, not on the database.
 *
 * @package    local_resourcelinkfix
 * @copyright  2026 UFSC
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @group      local_resourcelinkfix
 * @covers     \restore_local_resourcelinkfix_plugin
 */
final class rewrite_links_test extends advanced_testcase {
    /** Site where the backup was made. */
    const SOURCE = 'https://origem.example.org';
    /** Site where the course is being restored. */
    const TARGET = 'https://destino.example.net';
    /** Some third site, cited by the material. */
    const THIRD = 'https://terceiro.example.com';

    /**
     * Plugin with a restore state: cmids 101 and 102 came in the backup,
     * course 42 became 77. Cmid 103 is not in the map, as happens with a
     * module that was not fully restored (newitemid = 0).
     *
     * @param bool $rewritejs
     * @return local_resourcelinkfix_testable_plugin
     */
    protected function plugin($rewritejs = false) {
        $plugin = new local_resourcelinkfix_testable_plugin();
        $plugin->set_restore_state([
            'cmmap' => [101 => 201, 102 => 202],
            'oldcourseid' => 42,
            'newcourseid' => 77,
            'oldwwwroot' => self::SOURCE,
            'newwwwroot' => self::TARGET,
            'rewritejs' => $rewritejs,
        ]);
        return $plugin;
    }

    /**
     * A cmid that came in the backup is replaced by the new one.
     */
    public function test_backup_cmid_is_replaced() {
        $plugin = $this->plugin();
        $this->assertSame(
            '../../mod/page/view.php?id=201',
            $plugin->rewrite('../../mod/page/view.php?id=101')
        );
        $this->assertSame(
            'mod/quiz/view.php?id=202',
            $plugin->rewrite('mod/quiz/view.php?id=102')
        );
    }

    /**
     * A cmid that did not come in the backup stays as it is.
     */
    public function test_cmid_outside_backup_is_untouched() {
        $plugin = $this->plugin();
        $this->assertSame(
            '../../mod/chat/view.php?id=103',
            $plugin->rewrite('../../mod/chat/view.php?id=103')
        );
        $this->assertSame(
            '../../mod/page/view.php?id=9999',
            $plugin->rewrite('../../mod/page/view.php?id=9999')
        );
    }

    /**
     * course/view.php and a module's index.php take the COURSE id.
     */
    public function test_course_id_is_replaced() {
        $plugin = $this->plugin();
        $this->assertSame('/course/view.php?id=77', $plugin->rewrite('/course/view.php?id=42'));
        $this->assertSame('/mod/forum/index.php?id=77', $plugin->rewrite('/mod/forum/index.php?id=42'));
    }

    /**
     * Another course on the same site is not the restored course.
     */
    public function test_other_course_id_is_untouched() {
        $plugin = $this->plugin();
        $this->assertSame('/course/view.php?id=99', $plugin->rewrite('/course/view.php?id=99'));
    }

    /**
     * complete.php takes a cmid, like view.php.
     */
    public function test_complete_php_is_treated_as_cmid() {
        $plugin = $this->plugin();
        $this->assertSame(
            '../../mod/questionnaire/complete.php?id=201',
            $plugin->rewrite('../../mod/questionnaire/complete.php?id=101')
        );
    }

    /**
     * In a backup from another site, the old host goes together with the id.
     */
    public function test_source_host_is_replaced_with_the_id() {
        $plugin = $this->plugin();
        $this->assertSame(
            self::TARGET . '/mod/page/view.php?id=201',
            $plugin->rewrite(self::SOURCE . '/mod/page/view.php?id=101')
        );
        $this->assertSame(
            self::TARGET . '/course/view.php?id=77',
            $plugin->rewrite(self::SOURCE . '/course/view.php?id=42')
        );
    }

    /**
     * If the id does not change, neither does the host: the link stays valid there.
     *
     * Replacing only the host would point to this site with a foreign id, which
     * here may be another activity.
     */
    public function test_source_host_stays_when_the_id_does_not_change() {
        $plugin = $this->plugin();
        $this->assertSame(
            self::SOURCE . '/mod/chat/view.php?id=103',
            $plugin->rewrite(self::SOURCE . '/mod/chat/view.php?id=103')
        );
    }

    /**
     * A link to a third site is not touched: that id belongs to it.
     */
    public function test_third_site_is_not_touched() {
        $plugin = $this->plugin();
        $this->assertSame(
            self::THIRD . '/mod/page/view.php?id=101',
            $plugin->rewrite(self::THIRD . '/mod/page/view.php?id=101')
        );
        $this->assertSame(
            self::THIRD . '/course/view.php?id=42',
            $plugin->rewrite(self::THIRD . '/course/view.php?id=42')
        );
    }

    /**
     * A host split by hyphenation is PRESERVED, not fixed.
     *
     * Text pasted from a PDF arrives with the domain split ('https:// site',
     * 'exam- ple'). Fixing these links would mean guessing where the host
     * starts and ends - trying that is how the plugin came to read absolute
     * URLs as relative paths and remap the id of links to other Moodles. The
     * decision is not to guess: the link stays as it is and remains valid on
     * the source site.
     *
     * Measured on a real installation: 6 occurrences in 14,628 links.
     */
    public function test_host_broken_by_hyphenation_is_preserved() {
        $plugin = $this->plugin();
        $cases = [
            'space after the scheme' => 'https:// origem.example.org/mod/resource/view.php?id=101',
            'hyphenation mid-host'   => 'https://origem.exam- ple.org/mod/resource/view.php?id=101',
            'space after the dot'    => 'https://origem. example.org/mod/resource/view.php?id=101',
        ];
        foreach ($cases as $name => $url) {
            $this->assertSame($url, $plugin->rewrite($url), 'should preserve: ' . $name);
        }
    }

    /**
     * A third site with a split host is still a third site.
     */
    public function test_broken_third_site_is_not_touched() {
        $plugin = $this->plugin();
        $this->assertSame(
            'https://terceiro.exam ple.com/mod/page/view.php?id=101',
            $plugin->rewrite('https://terceiro.exam ple.com/mod/page/view.php?id=101')
        );
    }

    /**
     * A legitimate hyphen in a domain is not hyphenation.
     */
    public function test_legitimate_domain_hyphen_is_preserved() {
        $plugin = new local_resourcelinkfix_testable_plugin();
        $plugin->set_restore_state([
            'cmmap' => [101 => 201],
            'oldcourseid' => 42, 'newcourseid' => 77,
            'oldwwwroot' => 'https://meu-site.example.org',
            'newwwwroot' => self::TARGET,
        ]);
        $this->assertSame(
            self::TARGET . '/mod/page/view.php?id=201',
            $plugin->rewrite('https://meu-site.example.org/mod/page/view.php?id=101')
        );
        // A similar domain, but another one.
        $this->assertSame(
            'https://meu-site2.example.org/mod/page/view.php?id=101',
            $plugin->rewrite('https://meu-site2.example.org/mod/page/view.php?id=101')
        );
    }

    /**
     * Moodle installed in a subfolder: https://site/moodle.
     *
     * The host was only recognised when the domain came right before /mod/ or
     * /course/. With a subfolder, the URL was read as a relative path and a
     * third site's id ended up remapped.
     */
    public function test_host_with_subfolder_is_recognised() {
        $plugin = new local_resourcelinkfix_testable_plugin();
        $plugin->set_restore_state([
            'cmmap' => [101 => 201],
            'oldcourseid' => 42, 'newcourseid' => 77,
            'oldwwwroot' => 'https://origem.example.org/moodle',
            'newwwwroot' => 'https://destino.example.net/ead',
        ]);
        // Source site: host and id replaced together.
        $this->assertSame(
            'https://destino.example.net/ead/mod/page/view.php?id=201',
            $plugin->rewrite('https://origem.example.org/moodle/mod/page/view.php?id=101')
        );
        // Third site with a subfolder: nothing changes.
        $this->assertSame(
            'https://outro.example.com/moodle/mod/page/view.php?id=101',
            $plugin->rewrite('https://outro.example.com/moodle/mod/page/view.php?id=101')
        );
        // Two-level subfolder.
        $this->assertSame(
            'https://outro.example.com/lms/moodle/mod/page/view.php?id=101',
            $plugin->rewrite('https://outro.example.com/lms/moodle/mod/page/view.php?id=101')
        );
    }

    /**
     * Host with a non-default port.
     */
    public function test_host_with_port_is_recognised() {
        $plugin = new local_resourcelinkfix_testable_plugin();
        $plugin->set_restore_state([
            'cmmap' => [101 => 201],
            'oldcourseid' => 42, 'newcourseid' => 77,
            'oldwwwroot' => 'http://origem.example.org:8080',
            'newwwwroot' => self::TARGET,
        ]);
        $this->assertSame(
            self::TARGET . '/mod/page/view.php?id=201',
            $plugin->rewrite('http://origem.example.org:8080/mod/page/view.php?id=101')
        );
        // Third site with a port: untouched.
        $this->assertSame(
            'http://outro.example.com:8080/mod/page/view.php?id=101',
            $plugin->rewrite('http://outro.example.com:8080/mod/page/view.php?id=101')
        );
    }

    /**
     * A scheme-less (protocol-relative) URL has a host too.
     *
     * '//site/mod/...' is a valid form, common in exported HTML. Without
     * recognising it, the URL becomes a relative path and a third site's id
     * ends up remapped.
     */
    public function test_schemeless_url_is_recognised() {
        $plugin = $this->plugin();
        // Third site: untouched.
        $this->assertSame(
            '//terceiro.example.com/mod/page/view.php?id=101',
            $plugin->rewrite('//terceiro.example.com/mod/page/view.php?id=101')
        );
        $this->assertSame(
            '//terceiro.example.com/moodle/mod/page/view.php?id=101',
            $plugin->rewrite('//terceiro.example.com/moodle/mod/page/view.php?id=101')
        );
        // Scheme-less source site: id remapped, host replaced, and the
        // scheme-less form kept - it inherits the page's scheme.
        $this->assertSame(
            '//destino.example.net/mod/page/view.php?id=201',
            $plugin->rewrite('//origem.example.org/mod/page/view.php?id=101')
        );
    }

    /**
     * A URL with embedded credentials has a host too.
     */
    public function test_url_with_credentials_is_recognised() {
        $plugin = $this->plugin();
        $this->assertSame(
            'https://u:s@terceiro.example.com/mod/page/view.php?id=101',
            $plugin->rewrite('https://u:s@terceiro.example.com/mod/page/view.php?id=101')
        );
        $this->assertSame(
            'https://prof@terceiro.example.com/mod/page/view.php?id=101',
            $plugin->rewrite('https://prof@terceiro.example.com/mod/page/view.php?id=101')
        );
    }

    /**
     * When the host exists but is not recognisable, the id is not touched.
     *
     * The pattern's segment and character limits make some hosts not match.
     * The failure must be conservative: preserve, never remap an id that may
     * belong to another site.
     */
    public function test_unrecognisable_host_does_not_get_id_remapped() {
        $plugin = $this->plugin();
        $cases = [
            'segment with tilde' => 'https://terceiro.example.com/~prof/moodle/mod/page/view.php?id=101',
            'many segments'      => 'https://terceiro.example.com/a/b/c/d/e/f/g/h/i/mod/page/view.php?id=101',
            'percent sign'       => 'https://terceiro.example.com/a%20b/mod/page/view.php?id=101',
        ];
        foreach ($cases as $name => $url) {
            $this->assertSame($url, $plugin->rewrite($url), 'must not remap: ' . $name);
        }
    }

    /**
     * Any hint of an absolute URL preserves the link, even in a form the
     * plugin cannot read.
     *
     * The decision is deliberately conservative: when there is '://', a
     * leading '//' or credentials, and the host cannot be confirmed as the
     * source's, it is not touched. The cost of getting it wrong is pointing to
     * another Moodle with an id from here, which silently opens the wrong activity.
     */
    public function test_unforeseen_url_form_is_preserved() {
        $plugin = $this->plugin();
        $cases = [
            'IPv6'                => 'https://[2001:db8::1]/mod/page/view.php?id=101',
            'host with underscore' => 'https://meu_site.example.com/mod/page/view.php?id=101',
            'many segments'        => 'https://t.example.com/a/b/c/d/e/f/g/h/i/j/k/l/m/mod/page/view.php?id=101',
            'double slash'         => 'https://t.example.com//mod/page/view.php?id=101',
            'port and subfolder'   => 'https://t.example.com:8443/lms/mod/page/view.php?id=101',
            'uppercase scheme'     => 'HTTPS://T.EXAMPLE.COM/mod/page/view.php?id=101',
            'no scheme'            => '//t.example.com/mod/page/view.php?id=101',
            'with credentials'     => 'https://u:s@t.example.com/mod/page/view.php?id=101',
        ];
        foreach ($cases as $name => $url) {
            $this->assertSame($url, $plugin->rewrite($url), 'must not remap: ' . $name);
        }
    }

    /**
     * Relative paths are still fixed: the inversion must not turn the
     * plugin into one that does nothing.
     */
    public function test_relative_path_is_still_fixed() {
        $plugin = $this->plugin();
        $cases = [
            '../../mod/page/view.php?id=101'   => '../../mod/page/view.php?id=201',
            './mod/page/view.php?id=101'       => './mod/page/view.php?id=201',
            '/mod/page/view.php?id=101'        => '/mod/page/view.php?id=201',
            'mod/page/view.php?id=101'         => 'mod/page/view.php?id=201',
            '../mod/quiz/view.php?id=102'      => '../mod/quiz/view.php?id=202',
        ];
        foreach ($cases as $input => $expected) {
            $this->assertSame($expected, $plugin->rewrite($input), 'should fix: ' . $input);
        }
    }

    /**
     * Loose text before a relative path must not become a host.
     */
    public function test_text_before_path_does_not_become_host() {
        $plugin = $this->plugin();
        // Running text is not a place where a link value starts: left alone.
        $this->assertSame(
            'veja em https://x.example.org e depois mod/page/view.php?id=101',
            $plugin->rewrite('veja em https://x.example.org e depois mod/page/view.php?id=101')
        );
    }

    /**
     * A link built at run time is never changed: the number is not in the
     * file, and touching what surrounds it would break the code.
     */
    public function test_link_built_in_javascript_is_not_changed() {
        $plugin = $this->plugin(true);
        $cases = [
            "var u = 'mod/quiz/view.php?id=' + cmid;",
            'var u = "mod/quiz/view.php?id=" + id;',
            // phpcs:ignore moodle.Strings.ForbiddenStrings.Found -- A JS template literal is the case under test.
            'var u = `mod/quiz/view.php?id=${cmid}`;',
            'var u = "mod/quiz/view.php?id=" + window.cmid;',
        ];
        foreach ($cases as $js) {
            $this->assertSame($js, $plugin->rewrite($js), 'must not touch: ' . $js);
        }
    }

    /**
     * A literal URL inside .js is rewritten like any other.
     */
    public function test_literal_link_in_javascript_is_rewritten() {
        $plugin = $this->plugin(true);
        $js = "const links = { \"Questoes\": '" . self::SOURCE . "/mod/quiz/view.php?id=101' };";
        $expected = "const links = { \"Questoes\": '" . self::TARGET . "/mod/quiz/view.php?id=201' };";
        $this->assertSame($expected, $plugin->rewrite($js));
    }

    /**
     * A URL inside url(), CSS syntax embedded in .js.
     *
     * A form found in real material: the parenthesis does not separate the host
     * from the path, so the link is recognised like any other.
     */
    public function test_url_in_css_syntax_is_rewritten() {
        $plugin = $this->plugin(true);
        $this->assertSame(
            'background: url(' . self::TARGET . '/mod/resource/view.php?id=201);',
            $plugin->rewrite('background: url(' . self::SOURCE . '/mod/resource/view.php?id=101);')
        );
        // From a third site, it stays untouched.
        $this->assertSame(
            'background: url(' . self::THIRD . '/mod/resource/view.php?id=101);',
            $plugin->rewrite('background: url(' . self::THIRD . '/mod/resource/view.php?id=101);')
        );
    }

    /**
     * A single pass: an id already replaced is not remapped again.
     */
    public function test_replacement_in_a_single_pass() {
        $plugin = new local_resourcelinkfix_testable_plugin();
        $plugin->set_restore_state([
            'cmmap' => [10 => 20, 20 => 30],
            'oldcourseid' => 42, 'newcourseid' => 77,
        ]);
        $this->assertSame('/mod/page/view.php?id=20', $plugin->rewrite('/mod/page/view.php?id=10'));
        $this->assertSame('/mod/page/view.php?id=30', $plugin->rewrite('/mod/page/view.php?id=20'));
    }

    /**
     * Forms the plugin does not reach, on purpose.
     */
    public function test_forms_out_of_reach() {
        $plugin = $this->plugin();
        $cases = [
            // The id is not the first parameter.
            '/mod/page/view.php?forceview=1&id=101',
            // Script outside the pattern.
            '/mod/page/report.php?id=101',
            // Instance id, not cmid.
            '/mod/scorm/player.php?a=101',
            '/mod/data/edit.php?d=101',
            // Not an activity or course link.
            self::SOURCE . '/pluginfile.php/123/mod_resource/content/0/a.pdf',
            // Glued prefix.
            '/xmod/page/view.php?id=101',
        ];
        foreach ($cases as $url) {
            $this->assertSame($url, $plugin->rewrite($url), 'must not touch: ' . $url);
        }
    }

    /**
     * Neighbouring ids are not confused.
     */
    public function test_longer_or_shorter_id_is_not_confused() {
        $plugin = $this->plugin();
        $this->assertSame('/mod/page/view.php?id=1011', $plugin->rewrite('/mod/page/view.php?id=1011'));
        $this->assertSame('/mod/page/view.php?id=10', $plugin->rewrite('/mod/page/view.php?id=10'));
    }

    /**
     * Without original_wwwroot there is no way to know the source host:
     * absolute links stay untouched, relative ones are still fixed.
     */
    public function test_without_source_wwwroot_only_relative_is_fixed() {
        $plugin = new local_resourcelinkfix_testable_plugin();
        $plugin->set_restore_state([
            'cmmap' => [101 => 201],
            'oldcourseid' => 42, 'newcourseid' => 77,
            'oldwwwroot' => '', 'newwwwroot' => self::TARGET,
        ]);
        $this->assertSame(
            self::SOURCE . '/mod/page/view.php?id=101',
            $plugin->rewrite(self::SOURCE . '/mod/page/view.php?id=101')
        );
        $this->assertSame(
            '../../mod/page/view.php?id=201',
            $plugin->rewrite('../../mod/page/view.php?id=101')
        );
    }

    /**
     * The guard accepts the legitimate change: only the links changed.
     */
    public function test_guard_accepts_change_only_in_links() {
        $plugin = $this->plugin();
        $old = '<p>Texto</p><a href="../../mod/page/view.php?id=101">A</a><p>Fim</p>';
        $new = '<p>Texto</p><a href="../../mod/page/view.php?id=201">A</a><p>Fim</p>';
        $this->assertTrue($plugin->only_links_differ($old, $new));
    }

    /**
     * The guard blocks loss of content around the link.
     */
    public function test_guard_blocks_text_loss() {
        $plugin = $this->plugin();
        $old = '<p>Texto</p><a href="../../mod/page/view.php?id=101">A</a><p>Fim</p>';
        $cases = [
            'text gone'     => '<a href="../../mod/page/view.php?id=201">A</a><p>Fim</p>',
            'end gone'      => '<p>Texto</p><a href="../../mod/page/view.php?id=201">A</a>',
            'all empty'     => '',
            'link only'     => '../../mod/page/view.php?id=201',
            'text replaced' => '<p>Outro</p><a href="../../mod/page/view.php?id=201">A</a><p>Fim</p>',
        ];
        foreach ($cases as $name => $new) {
            $this->assertFalse(
                $plugin->only_links_differ($old, $new),
                'should block: ' . $name
            );
        }
    }

    /**
     * The guard blocks a link that appears or disappears.
     */
    public function test_guard_blocks_extra_or_missing_link() {
        $plugin = $this->plugin();
        $old = '<a href="../../mod/page/view.php?id=101">A</a>';
        $this->assertFalse($plugin->only_links_differ(
            $old,
            '<a href="../../mod/page/view.php?id=201">A</a><a href="../../mod/page/view.php?id=202">B</a>'
        ));
        $this->assertFalse($plugin->only_links_differ($old, '<a href="">A</a>'));
    }

    /**
     * The example file tells the truth about what happens to each link.
     *
     * example/navigation.html is executable documentation: each link has a
     * comment saying what happens to it. Without this test, the example starts
     * lying at the first behaviour change.
     */
    public function test_example_file_matches() {
        global $CFG;

        $plugin = $this->plugin();
        $before = file_get_contents($CFG->dirroot .
            '/local/resourcelinkfix/example/navigation.html');
        $after = $plugin->rewrite($before);

        // Promised as rewritten.
        foreach (
            [
            '../../mod/page/view.php?id=201',
            self::TARGET . '/mod/quiz/view.php?id=202',
            self::TARGET . '/course/view.php?id=77',
            '../../mod/forum/index.php?id=77',
            '../../mod/questionnaire/complete.php?id=201',
            ] as $expected
        ) {
            $this->assertContains(
                'href="' . $expected . '"',
                $after,
                'should have been rewritten to: ' . $expected
            );
        }

        // Promised as preserved.
        foreach (
            [
            'https://origem.exam- ple.org/mod/page/view.php?id=102',
            self::SOURCE . '/mod/chat/view.php?id=103',
            self::THIRD . '/mod/page/view.php?id=102',
            self::THIRD . '/course/view.php?id=42',
            'https://terceiro.exam ple.com/mod/page/view.php?id=102',
            '../../mod/page/view.php?forceview=1&amp;id=101',
            self::SOURCE . '/pluginfile.php/123/mod_resource/content/0/anexo.pdf',
            self::SOURCE . '/course/view.php?id=99',
            ] as $expected
        ) {
            $this->assertContains(
                'href="' . $expected . '"',
                $after,
                'should have been preserved: ' . $expected
            );
        }
    }

    /**
     * Content without any link comes out the same.
     */
    public function test_content_without_links_is_unchanged() {
        $plugin = $this->plugin();
        $this->assertSame('', $plugin->rewrite(''));
        $this->assertSame('<p>texto</p>', $plugin->rewrite('<p>texto</p>'));
    }

    /**
     * Two URLs glued in the same run are left alone, and nothing is lost.
     *
     * The decision used the last URL of the prefix and the replacement started
     * at the first one, so the first url() - an image from a CDN - was deleted
     * from the file, and the guard let it through.
     */
    public function test_two_glued_urls_are_preserved() {
        $plugin = $this->plugin();
        $css = '.x{background:url(http://cdn.example.com/a.png),url(' . self::SOURCE
            . '/mod/page/view.php?id=101)}';
        $this->assertSame($css, $plugin->rewrite($css));
        $this->assertNull($plugin->rewrite_file_for_test('<style>' . $css . '</style>'));
    }

    /**
     * A link from a third site that carries the source's URL in a parameter
     * belongs to the third site.
     */
    public function test_third_site_wrapping_source_url_is_preserved() {
        $plugin = $this->plugin();
        $url = self::THIRD . '/r.php?u=' . self::SOURCE . '/mod/page/view.php?id=101';
        $this->assertSame($url, $plugin->rewrite($url));
    }

    /**
     * Another Moodle in a subfolder of the source's host is another site.
     *
     * The base was compared by "starts with", so the subfolder passed for the
     * source, was dropped, and the link pointed to an activity here.
     */
    public function test_other_moodle_in_subfolder_of_source_host_is_preserved() {
        $plugin = $this->plugin();
        $url = self::SOURCE . '/outro/mod/page/view.php?id=101';
        $this->assertSame($url, $plugin->rewrite($url));
    }

    /**
     * A split host followed by a subfolder is preserved, like one without it.
     *
     * The space leaves the scheme out of the prefix, and only the last
     * segment was checked for a domain - with a subfolder, it is 'moodle'.
     */
    public function test_split_host_with_subfolder_is_preserved() {
        $plugin = $this->plugin();
        $cases = [
            'space after the scheme' => 'https:// terceiro.example.com/moodle/mod/page/view.php?id=101',
            'split third site'       => 'https://ter- ceiro.example.com/moodle/mod/page/view.php?id=101',
            'split source'           => 'https://ori- gem.example.org/moodle/mod/page/view.php?id=101',
        ];
        foreach ($cases as $name => $url) {
            $this->assertSame($url, $plugin->rewrite($url), 'should preserve: ' . $name);
        }
    }

    /**
     * A scheme-less source URL after a lead ('url(') is fixed, like one with a scheme.
     */
    public function test_schemeless_source_after_lead_is_fixed() {
        $plugin = $this->plugin();
        $this->assertSame(
            'url(//destino.example.net/mod/page/view.php?id=201)',
            $plugin->rewrite('url(//origem.example.org/mod/page/view.php?id=101)')
        );
    }

    /**
     * A source URL with another scheme than original_wwwroot's is still the
     * source, and the file with it is written.
     */
    public function test_source_with_other_scheme_is_fixed_and_written() {
        $plugin = $this->plugin();
        $old = '<a href="http://origem.example.org/mod/page/view.php?id=101">A</a>';
        $this->assertSame(
            '<a href="' . self::TARGET . '/mod/page/view.php?id=201">A</a>',
            $plugin->rewrite_file_for_test($old)
        );
    }

    /**
     * A source URL with credentials is preserved: the credentials are not
     * this site's, and dropping them would change more than the link.
     */
    public function test_source_with_credentials_is_preserved() {
        $plugin = $this->plugin();
        $url = 'https://prof@origem.example.org/mod/page/view.php?id=101';
        $this->assertSame($url, $plugin->rewrite($url));
    }

    /**
     * The length of the text glued before the path does not decide anything.
     *
     * It was read up to 300 characters. Past that, the start of the URL was
     * out of sight: a third site passed for a relative path, and a link late
     * in minified CSS was not seen at all. The prefix is now read whole, back
     * to the start of the run.
     */
    public function test_prefix_length_does_not_matter() {
        // Source in a subfolder long enough to put the prefix at each length.
        // 'https://origem.example.org/' has 27 characters, plus the folder and its slash.
        foreach ([299, 300, 301, 5000] as $length) {
            $root = 'https://origem.example.org/' . str_repeat('a', $length - 28);
            $plugin = new local_resourcelinkfix_testable_plugin();
            $plugin->set_restore_state([
                'cmmap' => [101 => 201],
                'oldcourseid' => 42, 'newcourseid' => 77,
                'oldwwwroot' => $root,
                'newwwwroot' => self::TARGET,
            ]);
            $url = $root . '/mod/page/view.php?id=101';
            $this->assertSame($length, strlen($root . '/'), 'prefix length');
            $this->assertSame(
                self::TARGET . '/mod/page/view.php?id=201',
                $plugin->rewrite($url),
                'source, prefix of ' . $length
            );
        }

        // Third site: preserved at every length.
        $plugin = $this->plugin();
        foreach ([299, 300, 301, 306, 307, 400, 5000] as $length) {
            $root = 'https://terceiro.example.com/' . str_repeat('x', $length - 30);
            $this->assertSame($length, strlen($root . '/'), 'prefix length');
            $url = $root . '/mod/page/view.php?id=101';
            $this->assertSame($url, $plugin->rewrite($url), 'third site, prefix of ' . $length);
        }
    }

    /**
     * The guard sees text lost inside the prefix.
     *
     * It masked the whole match, prefix included, so anything the rewrite
     * dropped there was invisible to it.
     */
    public function test_guard_blocks_text_lost_in_prefix() {
        $plugin = $this->plugin();
        $this->assertFalse($plugin->only_links_differ(
            '<p>a</p>foo,bar/../../mod/page/view.php?id=101<p>b</p>',
            '<p>a</p>../../mod/page/view.php?id=201<p>b</p>'
        ));
        $this->assertFalse($plugin->only_links_differ(
            'url(http://cdn.example.com/a.png),url(' . self::SOURCE . '/mod/page/view.php?id=101)',
            'url(' . self::TARGET . '/mod/page/view.php?id=201)'
        ));
    }

    /**
     * The guard still accepts the source's authority becoming this site's.
     */
    public function test_guard_accepts_authority_change() {
        $plugin = $this->plugin();
        $this->assertTrue($plugin->only_links_differ(
            '<a href="' . self::SOURCE . '/mod/page/view.php?id=101">A</a>',
            '<a href="' . self::TARGET . '/mod/page/view.php?id=201">A</a>'
        ));
        $this->assertTrue($plugin->only_links_differ(
            'url(//origem.example.org/mod/page/view.php?id=101)',
            'url(//destino.example.net/mod/page/view.php?id=201)'
        ));
    }
    /**
     * A line break or tab inside an address does not turn it into a relative path.
     *
     * Browsers drop them from URLs, so the link still goes to the third site.
     * The plugin sees the text before the break and leaves the link alone.
     */
    public function test_third_site_broken_by_line_break_is_preserved() {
        $plugin = $this->plugin();
        $cases = [
            'newline before the path' => "<a href=\"https://terceiro.example.com/\nmod/page/view.php?id=101\">x</a>",
            'newline in a subfolder'  => "<a href=\"https://terceiro.example.com/moo\ndle/mod/page/view.php?id=101\">x</a>",
            'CRLF after the host'     => "<a href=\"https://terceiro.example.com/moodle/\r\nmod/page/view.php?id=101\">x</a>",
            'tab in a subfolder'      => "<a href=\"https://terceiro.example.com/lms\t/mod/page/view.php?id=101\">x</a>",
        ];
        foreach ($cases as $name => $html) {
            $this->assertSame($html, $plugin->rewrite($html), 'should preserve: ' . $name);
        }
    }

    /**
     * A split address whose last piece does not look like a domain is still preserved.
     */
    public function test_split_address_without_domain_piece_is_preserved() {
        $plugin = $this->plugin();
        $cases = [
            'IPv4 after a space'      => 'https:// 10.0.0.5/mod/page/view.php?id=101',
            'localhost after a space' => 'http:// localhost/mod/page/view.php?id=101',
            'space after the dot'     => 'https://terceiro.example. com/mod/page/view.php?id=101',
            'split top-level domain'  => 'https://terceiro.example.c om/mod/page/view.php?id=101',
            'hyphenated subfolder'    => 'https://terceiro.example.com/moo- dle/mod/page/view.php?id=101',
            'space in a subfolder'    => '<a href="https://terceiro.example.com/moodle 2/mod/page/view.php?id=101">x</a>',
            'split port'              => 'https://terceiro.example.com: 8080/mod/page/view.php?id=101',
            'split IP'                => 'https://10.0.0.- 5/mod/page/view.php?id=101',
            'split twice'             => 'https:// ter- ceiro.example.com/moo dle/mod/page/view.php?id=101',
        ];
        foreach ($cases as $name => $url) {
            $this->assertSame($url, $plugin->rewrite($url), 'should preserve: ' . $name);
        }
    }

    /**
     * The piece before a space only matters when it looks like part of an address.
     *
     * Running text, or a space after a closed attribute, still lets a relative
     * link be fixed.
     */
    public function test_text_before_a_space_that_is_not_an_address() {
        $plugin = $this->plugin();
        $this->assertContains(
            'view.php?id=201',
            $plugin->rewrite("<a title=\"x\"\nhref=../../mod/page/view.php?id=101>x</a>")
        );
        // Outside a link value - running text, a loose quoted string - the link stays.
        foreach (['veja mod/page/view.php?id=101', '<a href="/">t</a> "../mod/page/view.php?id=101"'] as $content) {
            $this->assertSame($content, $plugin->rewrite($content));
        }
    }

    /**
     * Every link in a run without spaces is read, however far into it.
     */
    public function test_every_link_in_a_run_is_fixed() {
        $plugin = $this->plugin();
        $this->assertSame(
            '<a href="../mod/page/view.php?id=201">a</a><a href="../mod/page/view.php?id=202">b</a>',
            $plugin->rewrite('<a href="../mod/page/view.php?id=101">a</a><a href="../mod/page/view.php?id=102">b</a>')
        );

        $filler = str_repeat('.c{color:red}', 30);
        $out = $plugin->rewrite('<style>.a{background:url(../mod/page/view.php?id=101)}' . $filler
            . '.b{background:url(../mod/page/view.php?id=102)}</style>');
        $this->assertContains('view.php?id=201', $out);
        $this->assertContains('view.php?id=202', $out);

        // A source URL after a relative link in the same run.
        $this->assertSame(
            'url(../mod/page/view.php?id=201),url(' . self::TARGET . '/mod/page/view.php?id=202)',
            $plugin->rewrite('url(../mod/page/view.php?id=101),url(' . self::SOURCE . '/mod/page/view.php?id=102)')
        );
    }

    /**
     * An '@' only counts as credentials when a host follows it.
     */
    public function test_at_sign_counts_only_before_a_host() {
        $plugin = $this->plugin();
        $this->assertContains(
            'view.php?id=201',
            $plugin->rewrite('<style>@media(max-width:600px){.b{background:url(../mod/page/view.php?id=101)}}</style>')
        );
        foreach (['prof@terceiro.example.com/mod/page/view.php?id=101', 'u:s@10.0.0.5/mod/page/view.php?id=101'] as $url) {
            $this->assertSame($url, $plugin->rewrite($url), 'should preserve: ' . $url);
        }
    }

    /**
     * The host is compared ignoring case; the path is not.
     */
    public function test_case_of_host_and_path() {
        $plugin = new local_resourcelinkfix_testable_plugin();
        $plugin->set_restore_state([
            'cmmap' => [101 => 201],
            'oldcourseid' => 42, 'newcourseid' => 77,
            'oldwwwroot' => 'https://h.example.org/moodle',
            'newwwwroot' => self::TARGET,
        ]);
        $this->assertSame(
            self::TARGET . '/mod/page/view.php?id=201',
            $plugin->rewrite('HTTPS://H.EXAMPLE.ORG/moodle/mod/page/view.php?id=101')
        );
        $url = 'https://h.example.org/MOODLE/mod/page/view.php?id=101';
        $this->assertSame($url, $plugin->rewrite($url));
    }

    /**
     * The guard accepts a new wwwroot that extends the old one.
     */
    public function test_guard_accepts_new_root_extending_the_old() {
        $plugin = new local_resourcelinkfix_testable_plugin();
        $plugin->set_restore_state([
            'cmmap' => [101 => 201, 102 => 202],
            'oldcourseid' => 42, 'newcourseid' => 77,
            'oldwwwroot' => 'https://ead.example.org',
            'newwwwroot' => 'https://ead.example.org/moodle',
        ]);
        $this->assertSame(
            '<a href="https://ead.example.org/moodle/mod/page/view.php?id=201">A</a>'
                . '<a href="../mod/page/view.php?id=202">B</a>',
            $plugin->rewrite_file_for_test('<a href="https://ead.example.org/mod/page/view.php?id=101">A</a>'
                . '<a href="../mod/page/view.php?id=102">B</a>')
        );
    }

    /**
     * The guard only accepts the wwwroot swap at the start of the link's URL.
     */
    public function test_guard_blocks_root_swap_inside_another_url() {
        $plugin = $this->plugin();
        $this->assertFalse($plugin->only_links_differ(
            'x ' . self::THIRD . '/r.php?u=' . self::SOURCE . '/mod/page/view.php?id=101 y',
            'x ' . self::THIRD . '/r.php?u=' . self::TARGET . '/mod/page/view.php?id=201 y'
        ));
    }

    /**
     * What the measuring tool counts as reached is what the plugin rewrites.
     */
    public function test_reader_agrees_with_rewrite() {
        $reader = new \local_resourcelinkfix\link_reader(self::SOURCE);
        $contents = [
            '<style>' . str_repeat('.c{color:red}', 30) . '.b{background:url(../mod/page/view.php?id=101)}</style>',
            '<a href="' . self::SOURCE . '/mod/page/view.php?id=101">a</a> '
                . '<a href="//origem.example.org/mod/page/view.php?id=102">b</a>',
            "<a href=\"https://terceiro.example.com/\nmod/page/view.php?id=101\">x</a>",
            'url(http://cdn.example.com/a.png),url(' . self::SOURCE . '/mod/page/view.php?id=101)',
        ];
        foreach ($contents as $content) {
            $reached = 0;
            foreach ($reader->find($content) as $link) {
                if ($link['source'] !== false) {
                    $reached++;
                }
            }
            // A fresh plugin: the count adds up across calls.
            $plugin = $this->plugin();
            $plugin->rewrite($content);
            $this->assertSame($plugin->get_linkcount(), $reached, 'content: ' . substr($content, -80));
        }
    }
    /**
     * A colon and a space before a relative link are CSS or running text, not an address.
     */
    public function test_relative_link_after_colon_and_space_is_fixed() {
        $plugin = $this->plugin();
        $cases = [
            'style block'    => '<style>.b { background: url(../mod/page/view.php?id=101); }</style>',
            'inline style'   => '<div style="background-image: url(../mod/page/view.php?id=101)">x</div>',
            'minified style' => '<style>.box{background: url(../mod/page/view.php?id=101)}</style>',
        ];
        foreach ($cases as $name => $content) {
            $this->assertContains('view.php?id=201', $plugin->rewrite($content), 'should fix: ' . $name);
        }
    }

    /**
     * Line breaks and tabs belong to the address: browsers drop them.
     *
     * However many there are, and whatever comes between them, the address is
     * read as one - a third site stays a third site.
     */
    public function test_line_breaks_are_part_of_the_address() {
        $plugin = $this->plugin();
        $cases = [
            'two newlines'           => "<a href=\"https://terceiro.example.com/\nmoodle\n/mod/page/view.php?id=101\">x</a>",
            'two tabs'               => "<a href=\"https://terceiro.example.com/\tlms\t/mod/page/view.php?id=101\">x</a>",
            'source after a newline' => "<a href=\"https://terceiro.example.com\n"
                . "//origem.example.org/mod/page/view.php?id=101\">x</a>",
            'parameter after break'  => "<a href=\"https://terceiro.example.com/go?to=\n"
                . "https://origem.example.org/mod/page/view.php?id=101\">x</a>",
        ];
        foreach ($cases as $name => $html) {
            $this->assertSame($html, $plugin->rewrite($html), 'should preserve: ' . $name);
        }
    }

    /**
     * Forms that browsers turn into an absolute address are absolute.
     *
     * A single slash after the scheme and backslashes are normalised to
     * 'https://' by browsers.
     */
    public function test_absolute_without_double_slash_is_preserved() {
        $plugin = $this->plugin();
        $cases = [
            'single slash'    => '<a href="https:/10.0.0.5/mod/page/view.php?id=101">x</a>',
            'backslashes'     => '<a href="https:\\\\terceiro.example.com\\moodle/mod/page/view.php?id=101">x</a>',
            'slash-backslash' => '<a href="/\\10.0.0.5/mod/page/view.php?id=101">x</a>',
            'source, backslashes' => '<a href="https:\\\\origem.example.org\\mod/page/view.php?id=101">x</a>',
        ];
        foreach ($cases as $name => $html) {
            $this->assertSame($html, $plugin->rewrite($html), 'should preserve: ' . $name);
        }
        // A backslash may be a path separator or an escape ('\\x2f' in JS,
        // '\\00002f' in CSS): any backslash leaves the link alone.
        $this->assertSame(
            '..\\..\\mod/page/view.php?id=101',
            $plugin->rewrite('..\\..\\mod/page/view.php?id=101')
        );
    }

    /**
     * The measuring tool shows the link itself, and the host of the link's own URL.
     */
    public function test_reader_describes_links_for_the_measuring_tool() {
        $reader = new \local_resourcelinkfix\link_reader(self::SOURCE);
        $links = $reader->find('<style>.a{background:url(http://cdn.example.com/a.png)}' . str_repeat('.c{color:red}', 20)
            . ".b{background:\nurl(../mod/page/view.php?id=101)}</style>"
            . '<a href="' . self::SOURCE . '/mod/page/view.php?id=102">b</a> <a href="../mod/page/view.php?id=103">c</a>');

        $this->assertFalse($links[0]['source']);
        $excerpt = \local_resourcelinkfix\link_reader::excerpt($links[0], 60);
        $this->assertLessThanOrEqual(60, strlen($excerpt));
        $this->assertContains('mod/page/view.php?id=101', $excerpt);
        $this->assertNotContains("\n", $excerpt);

        $this->assertFalse(\local_resourcelinkfix\link_reader::host_of($links[0]));
        $this->assertSame('https://origem.example.org', \local_resourcelinkfix\link_reader::host_of($links[1]));
        $this->assertNull(\local_resourcelinkfix\link_reader::host_of($links[2]));
    }
    /**
     * Every form a browser reads as another site's address is left alone.
     *
     * The prefix and the piece before a space are read the same way: line
     * breaks and tabs dropped, HTML entities decoded, backslashes as slashes.
     * A scheme with no slash at all is absolute too: 'http:host' is read as
     * 'http://host' on a page with another scheme.
     */
    public function test_browser_forms_of_another_site_are_preserved() {
        $plugin = $this->plugin();
        $cases = [
            'scheme without slash'      => '<a href="http:10.0.0.5/mod/page/view.php?id=101">x</a>',
            'https without slash'       => '<a href="https:10.0.0.5/mod/page/view.php?id=101">x</a>',
            'single-label host'         => '<a href="http:moodle/mod/page/view.php?id=101">x</a>',
            'space, newline, space'     => "<p>https://terceiro.example.com/moodle/ \n mod/page/view.php?id=101</p>",
            'space, tab, space'         => "<p>https://terceiro.example.com/moodle/ \t mod/page/view.php?id=101</p>",
            'space, newline, indent'    => "<p>Acesse terceiro.example.com/moodle/ \n    mod/page/view.php?id=101</p>",
            'scheme, space, newline'    => "https:// \n 10.0.0.5/mod/page/view.php?id=101",
            'space, CRLF, space'        => "<p>https://terceiro.example.com/moodle/ \r\n mod/page/view.php?id=101</p>",
            'decimal entity slashes'    => '<a href="https:&#47;&#47;10.0.0.5/mod/page/view.php?id=101">x</a>',
            'hex entity slashes'        => '<a href="https:&#x2F;&#x2F;10.0.0.5/mod/page/view.php?id=101">x</a>',
            'named entity slashes'      => '<a href="&sol;&sol;10.0.0.5/mod/page/view.php?id=101">x</a>',
            'word glued across a break' => "<p>Veja\nhttps:/10.0.0.5/mod/page/view.php?id=101</p>",
            'backslashes before space'  => "<p>https:\\\\terceiro.example.com\\moodle\\ mod/page/view.php?id=101</p>",
            'domain and colon'          => '<p>terceiro.example.com: 8080/mod/page/view.php?id=101</p>',
        ];
        foreach ($cases as $name => $content) {
            $this->assertSame($content, $plugin->rewrite($content), 'should preserve: ' . $name);
        }
    }

    /**
     * Encoded and escaped forms of another site's address are left alone.
     *
     * A relative link is only accepted when its prefix is a plain path after a
     * plain lead: anything else - entities, escapes, a scheme - is doubt.
     */
    public function test_encoded_and_escaped_forms_are_preserved() {
        $plugin = $this->plugin();
        $path = 'mod/page/view.php?id=101';
        $cases = [
            'tab entity between slashes'      => '<a href="/&Tab;/10.0.0.5/' . $path . '">x</a>',
            'newline entity between slashes'  => '<a href="/&#10;/10.0.0.5/' . $path . '">x</a>',
            'hex tab entity, single label'    => '<a href="/&#x9;/moodle/' . $path . '">x</a>',
            'named newline entity, port'      => '<a href="/&NewLine;/moodle:8080/' . $path . '">x</a>',
            'decimal reference, no semicolon' => '<a href="&#47&#47;10.0.0.5/' . $path . '">x</a>',
            'hex reference, no semicolon'     => '<a href="&#x2f&#x2f10.0.0.5/' . $path . '">x</a>',
            'CSS escape'                      => '<style>.a{background:url("\\00002f\\00002f10.0.0.5/' . $path . '")}</style>',
            'JS escape'                       => '<script>location.href="\\x2f\\x2f10.0.0.5/' . $path . '";</script>',
            'single slash, source inside'     => '<a href="https:/10.0.0.5/r?u=' . self::SOURCE . '/' . $path . '">x</a>',
            'no slash, source inside'         => '<a href="http:10.0.0.5/r?u=' . self::SOURCE . '/' . $path . '">x</a>',
        ];
        foreach ($cases as $name => $content) {
            $this->assertSame($content, $plugin->rewrite($content), 'should preserve: ' . $name);
        }
    }

    /**
     * What comes before a relative path must be plain for the link to be fixed.
     *
     * A slash there means the path may continue something else - a folder, an
     * address, a parameter - and the link is left alone.
     */
    public function test_lead_before_a_relative_path() {
        $plugin = $this->plugin();
        $fixed = [
            'CSS lead' => '<style>@media(max-width:600px){.b{background:url(../mod/page/view.php?id=101)}}</style>',
        ];
        foreach ($fixed as $name => $content) {
            $this->assertContains('view.php?id=201', $plugin->rewrite($content), 'should fix: ' . $name);
        }
        $preserved = [
            'folder before'  => '<a href="pasta/index.php?next=../mod/page/view.php?id=101">x</a>',
            'image before'   => '<style>.a{background:url(img/a.png)}.b{background:url(../mod/page/view.php?id=101)}</style>',
            'credentials'    => 'u:s@10.0.0.5/mod/page/view.php?id=101',
            'parameter lead' => '<a href="index.php?a=1&amp;next=../mod/page/view.php?id=101">x</a>',
            'call lead'      => '<a onclick="go(&quot;../mod/page/view.php?id=101&quot;)">x</a>',
        ];
        foreach ($preserved as $name => $content) {
            $this->assertSame($content, $plugin->rewrite($content), 'should preserve: ' . $name);
        }
    }
    /**
     * A relative link is only fixed where a link value starts.
     *
     * In HTML: right after 'href=', 'src=' (quoted or not) or a CSS 'url('.
     * Anywhere else the plugin cannot know whose address the path continues -
     * see DESIGN.md, which records why reading the text before the path was
     * abandoned.
     */
    public function test_relative_links_only_where_a_value_starts() {
        $plugin = $this->plugin();
        $path = 'mod/page/view.php?id=101';
        $fixed = [
            'href, double quotes'  => '<a href="../' . $path . '">x</a>',
            'href, single quotes'  => "<a href='../" . $path . "'>x</a>",
            'href, no quotes'      => '<a href=../' . $path . '>x</a>',
            'href, spaces, upper'  => '<a HREF = "../' . $path . '">x</a>',
            'src'                  => '<iframe src="../' . $path . '"></iframe>',
            'CSS url()'            => '<style>.b{background:url(../' . $path . ')}</style>',
            'CSS url() with quote' => '<style>.b{background:url( "../' . $path . '")}</style>',
            'start of the text'    => '../' . $path,
        ];
        foreach ($fixed as $name => $content) {
            $this->assertContains('view.php?id=201', $plugin->rewrite($content), 'should fix: ' . $name);
        }
        $other = 'https://10.0.0.5';
        $preserved = [
            'running text'              => '<p>Atividade: ' . $path . '</p>',
            'onclick'                   => '<a onclick="go(&quot;../' . $path . '&quot;)">x</a>',
            'inline script'             => "<script>var u = '../" . $path . "';</script>",
            'quote inside the value'    => '<a href="' . $other . '/\'/../' . $path . '">x</a>',
            'double quote inside value' => "<a href='" . $other . '/"/../' . $path . "'>x</a>",
            'less-than inside value'    => '<a href="' . $other . '/</../' . $path . '">x</a>',
            'greater-than inside value' => '<a href="' . $other . '/>/../' . $path . '">x</a>',
            'apostrophe inside value'   => '<a href="' . $other . '/l\'aula/../' . $path . '">x</a>',
            'parameter of another site' => '<a href="' . $other . '/mod/forum/view.php?id=5&amp;returnurl=/' . $path . '">x</a>',
            'JS concatenation'          => '<script>location.href="' . $other . '/"+"' . $path . '"</script>',
            'JS concatenation, slash'   => "<script>var u='" . $other . "'+'/" . $path . "'</script>",
            'JS URL with a base'        => '<script>var u = new URL("' . $path . '", "' . $other . '/");</script>',
            'ftp, one slash'            => '<a href="ftp:/10.0.0.5/' . $path . '">x</a>',
            'ftp, no slash'             => '<a href="ftp:10.0.0.5/' . $path . '">x</a>',
            'ftp, single label'         => '<a href="ftp:moodle/' . $path . '">x</a>',
            'wss, no slash'             => '<a href="wss:10.0.0.5/' . $path . '">x</a>',
            'ftp, domain and port'      => '<a href="ftp:other.example.org:21/' . $path . '">x</a>',
            'domain and port'           => '<a href="other.example.org:8080/' . $path . '">x</a>',
            'credentials, port'         => '<a href="u@other.example.org:8080/' . $path . '">x</a>',
        ];
        foreach ($preserved as $name => $content) {
            $this->assertSame($content, $plugin->rewrite($content), 'should preserve: ' . $name);
        }
    }

    /**
     * With a <base href>, relative links resolve elsewhere: none is fixed.
     *
     * Absolute links from the source carry their own host and are still fixed.
     */
    public function test_base_href_leaves_relative_links_alone() {
        $plugin = $this->plugin();
        $head = '<head><base href="https://10.0.0.5/"></head><a href="mod/page/view.php?id=101">a</a>';
        $this->assertSame(
            $head . '<a href="' . self::TARGET . '/mod/page/view.php?id=202">b</a>',
            $plugin->rewrite($head . '<a href="' . self::SOURCE . '/mod/page/view.php?id=102">b</a>')
        );
    }

    /**
     * In a .js file, a string literal that starts a value is a link value too.
     *
     * Only when rewriting .js is on, and only after '=' or ':' - an
     * assignment or a property. A literal after '+' continues another string;
     * an argument or an array item may be resolved against another base.
     */
    public function test_string_literal_is_a_value_in_javascript() {
        $plugin = $this->plugin(true);
        $path = 'mod/page/view.php?id=101';
        $fixed = [
            'assignment' => "var u = '../../" . $path . "';",
            'property'   => "{ link: '../" . $path . "' }",
        ];
        foreach ($fixed as $name => $js) {
            $this->assertContains('view.php?id=201', $plugin->rewrite($js, 'js'), 'should fix: ' . $name);
        }
        // An argument or an array item may be resolved against another base:
        // new URL(path, base), [base, path].join('/'), base.concat(path).
        $preserved = [
            'concatenation'        => 'location.href = "https://10.0.0.5/" + "' . $path . '";',
            'concatenation, slash' => "var u = 'https://10.0.0.5' + '/" . $path . "';",
            'argument'             => 'go("../' . $path . '");',
            'array'                => "['../" . $path . "']",
            'new URL with a base'  => 'var u = new URL("' . $path . '", "https://10.0.0.5/");',
            'join'                 => 'location.href = ["https://10.0.0.5", "' . $path . '"].join("/");',
            'concat()'             => 'location.href = "https://10.0.0.5/".concat("' . $path . '");',
        ];
        foreach ($preserved as $name => $js) {
            $this->assertSame($js, $plugin->rewrite($js, 'js'), 'should preserve: ' . $name);
        }
        // The same literal in an HTML file is not a link value.
        $this->assertSame($fixed['assignment'], $plugin->rewrite($fixed['assignment']));
    }
    /**
     * Any mention of a base address leaves the file's relative links alone.
     *
     * The plugin does not try to parse where a <base> is or what it says: a
     * '>' inside one of its attributes, a <base> encoded inside an iframe's
     * srcdoc or created by a script all change how relative links resolve.
     */
    public function test_any_base_leaves_relative_links_alone() {
        $plugin = $this->plugin(true);
        $link = '<a href="mod/page/view.php?id=101">x</a>';
        $html = [
            'quoted greater-than'  => '<base target=">" href="https://10.0.0.5/">' . $link,
            'encoded in srcdoc'    => '<iframe srcdoc="&lt;base href=\'https://10.0.0.5/\'&gt;'
                . '&lt;a href=\'mod/page/view.php?id=101\'&gt;">',
            'created by a script'  => "<script>var b = document.createElement('base');"
                . " b.href = 'https://10.0.0.5/';</script>" . $link,
            'double-quoted create' => '<script>document.head.append(document.createElement("base"))</script>' . $link,
        ];
        foreach ($html as $name => $content) {
            $this->assertSame($content, $plugin->rewrite($content), 'should preserve: ' . $name);
        }
        $js = "document.write('<base href=\"https://10.0.0.5/\">'); location.href = 'mod/page/view.php?id=101';";
        $this->assertSame($js, $plugin->rewrite($js, 'js'));
    }

    /**
     * Looking for a base address takes linear time.
     *
     * A pattern scanning up to the next '>' after each '<base' was quadratic
     * without the PCRE JIT - which PHP 5.6 does not have: 7.4 s for 20,000
     * '<base ' with no '>'.
     */
    public function test_looking_for_a_base_is_linear() {
        $plugin = $this->plugin();
        $content = '<!--' . str_repeat('<base ', 20000) . '--><a href="../mod/page/view.php?id=101">x</a>';

        $start = microtime(true);
        $result = $plugin->rewrite($content);
        $elapsed = microtime(true) - $start;

        $this->assertSame($content, $result);
        $this->assertLessThan(0.5, $elapsed, 'took ' . round($elapsed, 2) . ' s');
    }
}
