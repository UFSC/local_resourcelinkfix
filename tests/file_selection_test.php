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
 * Testes da selecao de arquivos.
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
 * Quais arquivos da area content entram na reescrita.
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
     * HTML entra sempre, independente da configuracao de .js.
     */
    public function test_html_entra_sempre() {
        foreach ([false, true] as $rewritejs) {
            $plugin = $this->plugin($rewritejs);
            $this->assertTrue($plugin->processes('index.html'));
            $this->assertTrue($plugin->processes('pagina.htm'));
            $this->assertTrue($plugin->processes('INDEX.HTML'));
        }
    }

    /**
     * .js so entra quando a configuracao esta ligada — e ela nasce desligada,
     * porque .js e codigo: um erro ali quebra a navegacao do recurso.
     */
    public function test_js_depende_da_configuracao() {
        $this->assertFalse($this->plugin(false)->processes('moodleface.js'));
        $this->assertTrue($this->plugin(true)->processes('moodleface.js'));
    }

    /**
     * O resto nunca entra, nem com .js ligado.
     */
    public function test_outras_extensoes_nunca_entram() {
        $plugin = $this->plugin(true);
        foreach (['style.css', 'material.pdf', 'dados.json', 'foto.png', 'leiame.txt'] as $nome) {
            $this->assertFalse($plugin->processes($nome), $nome . ' nao deveria entrar');
        }
    }

    /**
     * O padrao do plugin, sem configuracao gravada, e nao mexer em .js.
     */
    public function test_padrao_e_nao_mexer_em_js() {
        $plugin = new local_resourcelinkfix_testable_plugin();
        $this->assertFalse($plugin->processes('nav.js'));
        $this->assertTrue($plugin->processes('index.html'));
    }
}
