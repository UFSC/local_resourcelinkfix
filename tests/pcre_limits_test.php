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
 * Testes do comportamento sob limites do PCRE.
 *
 * @package    local_resourcelinkfix
 * @copyright  2026 UFSC
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/resourcelinkfix/tests/fixtures/testable_plugin.php');

/**
 * Quando o PCRE aborta, preg_replace_callback() devolve null em vez de
 * lancar. Tratar null como "conteudo novo" apagaria o arquivo.
 *
 * @package    local_resourcelinkfix
 * @copyright  2026 UFSC
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @group      local_resourcelinkfix
 */
class local_resourcelinkfix_pcre_limits_testcase extends advanced_testcase {

    /** @var string Valor original de pcre.backtrack_limit. */
    protected $backtrack;

    /**
     * Guarda o limite original, para nao vazar para outros testes.
     */
    protected function setUp() {
        $this->backtrack = ini_get('pcre.backtrack_limit');
    }

    /**
     * Devolve o limite original.
     */
    protected function tearDown() {
        ini_set('pcre.backtrack_limit', $this->backtrack);
    }

    /**
     * @return local_resourcelinkfix_testable_plugin
     */
    protected function plugin() {
        $plugin = new local_resourcelinkfix_testable_plugin();
        $plugin->set_restore_state(array(
            'cmmap' => array(101 => 201),
            'oldcourseid' => 42, 'newcourseid' => 77,
            'oldwwwroot' => 'https://origem.example.org',
            'newwwwroot' => 'https://destino.example.net',
        ));
        return $plugin;
    }

    /**
     * Com o PCRE abortando de verdade, a reescrita devolve null.
     *
     * Este teste fixa o limiar: o valor usado precisa fazer o PCRE abortar,
     * senao o teste seguinte passa por engano. Medido neste ambiente: com
     * backtrack_limit=100 o padrao ainda conclui; a partir de 30 ele aborta.
     */
    public function test_limite_escolhido_realmente_aborta_o_pcre() {
        ini_set('pcre.backtrack_limit', '10');

        $plugin = $this->plugin();
        $result = $plugin->rewrite($this->conteudo_pesado());

        $this->assertNull($result, 'o limite escolhido nao fez o PCRE abortar; '
            . 'sem isso os testes de defesa passam sem exercitar nada');
        $this->assertSame(PREG_BACKTRACK_LIMIT_ERROR, preg_last_error());
    }

    /**
     * Com o PCRE abortado, rewrite_file() recusa o arquivo em vez de gravar.
     *
     * O retorno null nao pode virar conteudo: gravado, apagaria o arquivo.
     */
    public function test_rewrite_file_recusa_quando_o_pcre_aborta() {
        $this->resetAfterTest(true);
        ini_set('pcre.backtrack_limit', '10');

        $plugin = $this->plugin();
        $this->setExpectedException('moodle_exception');
        $plugin->rewrite_file_for_test($this->conteudo_pesado());
    }

    /**
     * Com o PCRE abortado, a trava nega: sem mascara nao ha verificacao.
     */
    public function test_trava_nega_quando_nao_consegue_verificar() {
        ini_set('pcre.backtrack_limit', '10');

        $plugin = $this->plugin();
        $pesado = $this->conteudo_pesado();

        $this->assertFalse($plugin->only_links_differ($pesado, $pesado),
            'sem mascara confiavel a trava tem de negar, mesmo com textos iguais');
    }

    /**
     * Conteudo que faz o PCRE trabalhar o bastante para abortar sob limite
     * baixo, e que contem um link a reescrever.
     *
     * @return string
     */
    protected function conteudo_pesado() {
        return '<p>http://x ' . str_repeat('palavra ', 3000) . '</p>'
            . '<a href="../../mod/page/view.php?id=101">link</a>';
    }

    /**
     * Sequencia longa sem espaco (imagem embutida em base64) nao pode
     * disparar backtracking quadratico.
     *
     * Um prefixo guloso sem ancora custou 10,5 s para 30 KB neste mesmo
     * conteudo, contra poucos milissegundos na forma ancorada. Em um acervo
     * com varios arquivos assim, a diferenca e entre segundos e horas.
     */
    public function test_sequencia_sem_espaco_nao_explode() {
        $plugin = $this->plugin();
        $base64 = str_repeat('AAAABBBBCCCCDDDD1234567890abcdefGHIJKL', 800);
        $content = '<img src="data:image/png;base64,' . $base64 . '">'
            . '<a href="../../mod/page/view.php?id=101">link</a>';

        $start = microtime(true);
        $result = $plugin->rewrite($content);
        $elapsed = microtime(true) - $start;

        $this->assertContains('view.php?id=201', $result);
        $this->assertLessThan(2.0, $elapsed,
            'a reescrita levou ' . round($elapsed, 2) . ' s: sinal de backtracking');
    }

    /**
     * Conteudo longo com muitas palavras apos um http:// nao pode derrubar
     * o processo nem estourar limite.
     */
    public function test_conteudo_longo_apos_esquema_nao_estoura() {
        $plugin = $this->plugin();
        $content = '<p>veja em http://exemplo.example.org ' . str_repeat('palavra ', 9000)
            . '</p><a href="../../mod/page/view.php?id=101">link</a>';

        $result = $plugin->rewrite($content);

        $this->assertNotNull($result);
        $this->assertSame(0, preg_last_error(),
            'o PCRE nao deveria abortar: erro ' . preg_last_error());
        $this->assertContains('view.php?id=201', $result);
    }
}
