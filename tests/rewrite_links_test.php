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
        $this->assertSame(
            'veja em https://x.example.org e depois mod/page/view.php?id=201',
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
}
