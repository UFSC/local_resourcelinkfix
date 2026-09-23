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
 * Tests for file selection.
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
require_once($CFG->dirroot . '/local/resourcelinkfix/tests/fixtures/testable_plugin.php');

/**
 * Which files in the content area are rewritten.
 *
 * @package    local_resourcelinkfix
 * @copyright  2026 UFSC
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @group      local_resourcelinkfix
 */
class file_selection_test extends advanced_testcase {
    /**
     * Builds a plugin instance with the given .js setting.
     *
     * @param bool $rewritejs
     * @return local_resourcelinkfix_testable_plugin
     */
    protected function plugin($rewritejs) {
        $plugin = new local_resourcelinkfix_testable_plugin();
        $plugin->set_restore_state(['rewritejs' => $rewritejs]);
        return $plugin;
    }

    /**
     * HTML is always included, whatever the .js setting.
     */
    public function test_html_is_always_included() {
        foreach ([false, true] as $rewritejs) {
            $plugin = $this->plugin($rewritejs);
            $this->assertTrue($plugin->processes('index.html'));
            $this->assertTrue($plugin->processes('pagina.htm'));
            $this->assertTrue($plugin->processes('INDEX.HTML'));
        }
    }

    /**
     * .js is only included when the setting is on — and it starts off,
     * because .js is code: a mistake there breaks the resource's navigation.
     */
    public function test_js_depends_on_the_setting() {
        $this->assertFalse($this->plugin(false)->processes('moodleface.js'));
        $this->assertTrue($this->plugin(true)->processes('moodleface.js'));
    }

    /**
     * Nothing else is ever included, not even with .js on.
     */
    public function test_other_extensions_are_never_included() {
        $plugin = $this->plugin(true);
        foreach (['style.css', 'material.pdf', 'dados.json', 'foto.png', 'leiame.txt'] as $name) {
            $this->assertFalse($plugin->processes($name), $name . ' should not be included');
        }
    }

    /**
     * With no stored setting, the plugin's default is to leave .js alone.
     */
    public function test_default_leaves_js_alone() {
        $plugin = new local_resourcelinkfix_testable_plugin();
        $this->assertFalse($plugin->processes('nav.js'));
        $this->assertTrue($plugin->processes('index.html'));
    }
}
