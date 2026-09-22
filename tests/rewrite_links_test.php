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
 * Testes da reescrita de links.
 *
 * @package    local_resourcelinkfix
 * @copyright  2026 UFSC
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');
require_once($CFG->dirroot . '/local/resourcelinkfix/tests/fixtures/testable_plugin.php');

/**
 * A reescrita de links depende so do estado do restore, nao do banco.
 *
 * @package    local_resourcelinkfix
 * @copyright  2026 UFSC
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @group      local_resourcelinkfix
 */
class local_resourcelinkfix_rewrite_links_testcase extends advanced_testcase {

    /** Site onde o backup foi feito. */
    const ORIGEM = 'https://origem.example.org';
    /** Site onde o curso esta sendo restaurado. */
    const DESTINO = 'https://destino.example.net';
    /** Um terceiro site qualquer, citado pelo material. */
    const TERCEIRO = 'https://terceiro.example.com';

    /**
     * Plugin com o estado de um restore: os cmids 101 e 102 vieram no backup,
     * o curso 42 virou 77. O cmid 103 nao entra no mapa, como acontece com
     * modulo que nao foi restaurado por completo (newitemid = 0).
     *
     * @param bool $rewritejs
     * @return local_resourcelinkfix_testable_plugin
     */
    protected function plugin($rewritejs = false) {
        $plugin = new local_resourcelinkfix_testable_plugin();
        $plugin->set_restore_state(array(
            'cmmap' => array(101 => 201, 102 => 202),
            'oldcourseid' => 42,
            'newcourseid' => 77,
            'oldwwwroot' => self::ORIGEM,
            'newwwwroot' => self::DESTINO,
            'rewritejs' => $rewritejs,
        ));
        return $plugin;
    }

    /**
     * Um cmid que veio no backup e trocado pelo novo.
     */
    public function test_cmid_do_backup_e_trocado() {
        $plugin = $this->plugin();
        $this->assertSame('../../mod/page/view.php?id=201',
            $plugin->rewrite($ref = '../../mod/page/view.php?id=101'));
        $this->assertSame('mod/quiz/view.php?id=202',
            $plugin->rewrite('mod/quiz/view.php?id=102'));
    }

    /**
     * Um cmid que nao veio no backup fica como esta.
     */
    public function test_cmid_fora_do_backup_fica_intacto() {
        $plugin = $this->plugin();
        $this->assertSame('../../mod/chat/view.php?id=103',
            $plugin->rewrite('../../mod/chat/view.php?id=103'));
        $this->assertSame('../../mod/page/view.php?id=9999',
            $plugin->rewrite('../../mod/page/view.php?id=9999'));
    }

    /**
     * course/view.php e o index.php de um modulo recebem o id do CURSO.
     */
    public function test_id_de_curso_e_trocado() {
        $plugin = $this->plugin();
        $this->assertSame('/course/view.php?id=77', $plugin->rewrite('/course/view.php?id=42'));
        $this->assertSame('/mod/forum/index.php?id=77', $plugin->rewrite('/mod/forum/index.php?id=42'));
    }

    /**
     * Outro curso do mesmo site nao e o curso restaurado.
     */
    public function test_id_de_outro_curso_fica_intacto() {
        $plugin = $this->plugin();
        $this->assertSame('/course/view.php?id=99', $plugin->rewrite('/course/view.php?id=99'));
    }

    /**
     * complete.php recebe um cmid, igual a view.php.
     */
    public function test_complete_php_e_tratado_como_cmid() {
        $plugin = $this->plugin();
        $this->assertSame('../../mod/questionnaire/complete.php?id=201',
            $plugin->rewrite('../../mod/questionnaire/complete.php?id=101'));
    }

    /**
     * Em backup de outro site, o host antigo sai junto com o id.
     */
    public function test_host_da_origem_e_trocado_junto_com_o_id() {
        $plugin = $this->plugin();
        $this->assertSame(self::DESTINO . '/mod/page/view.php?id=201',
            $plugin->rewrite(self::ORIGEM . '/mod/page/view.php?id=101'));
        $this->assertSame(self::DESTINO . '/course/view.php?id=77',
            $plugin->rewrite(self::ORIGEM . '/course/view.php?id=42'));
    }

    /**
     * Se o id nao muda, o host tambem nao: o link segue valido la.
     *
     * Trocar so o host apontaria para este site com um id alheio, que aqui
     * pode ser outra atividade.
     */
    public function test_host_da_origem_fica_quando_o_id_nao_muda() {
        $plugin = $this->plugin();
        $this->assertSame(self::ORIGEM . '/mod/chat/view.php?id=103',
            $plugin->rewrite(self::ORIGEM . '/mod/chat/view.php?id=103'));
    }

    /**
     * Link para um terceiro site nao e tocado: aquele id pertence a ele.
     */
    public function test_terceiro_site_nao_e_tocado() {
        $plugin = $this->plugin();
        $this->assertSame(self::TERCEIRO . '/mod/page/view.php?id=101',
            $plugin->rewrite(self::TERCEIRO . '/mod/page/view.php?id=101'));
        $this->assertSame(self::TERCEIRO . '/course/view.php?id=42',
            $plugin->rewrite(self::TERCEIRO . '/course/view.php?id=42'));
    }

    /**
     * Host partido por hifenizacao continua sendo host.
     *
     * Texto colado de PDF chega assim. Sem normalizar, a URL seria lida como
     * caminho relativo e o id de outro site acabaria remapeado.
     */
    public function test_host_quebrado_por_hifenizacao_e_reconhecido() {
        $plugin = $this->plugin();
        $esperado = self::DESTINO . '/mod/resource/view.php?id=201';
        $this->assertSame($esperado,
            $plugin->rewrite('https:// origem.example.org/mod/resource/view.php?id=101'));
        $this->assertSame($esperado,
            $plugin->rewrite('https://origem.exam- ple.org/mod/resource/view.php?id=101'));
        $this->assertSame($esperado,
            $plugin->rewrite('https://origem. example.org/mod/resource/view.php?id=101'));
    }

    /**
     * Terceiro site com o host partido continua sendo terceiro site.
     */
    public function test_terceiro_site_quebrado_nao_e_tocado() {
        $plugin = $this->plugin();
        $this->assertSame('https://terceiro.exam ple.com/mod/page/view.php?id=101',
            $plugin->rewrite('https://terceiro.exam ple.com/mod/page/view.php?id=101'));
    }

    /**
     * Hifen legitimo de dominio nao e hifenizacao.
     */
    public function test_hifen_legitimo_de_dominio_e_preservado() {
        $plugin = new local_resourcelinkfix_testable_plugin();
        $plugin->set_restore_state(array(
            'cmmap' => array(101 => 201),
            'oldcourseid' => 42, 'newcourseid' => 77,
            'oldwwwroot' => 'https://meu-site.example.org',
            'newwwwroot' => self::DESTINO,
        ));
        $this->assertSame(self::DESTINO . '/mod/page/view.php?id=201',
            $plugin->rewrite('https://meu-site.example.org/mod/page/view.php?id=101'));
        // Dominio parecido, mas outro.
        $this->assertSame('https://meu-site2.example.org/mod/page/view.php?id=101',
            $plugin->rewrite('https://meu-site2.example.org/mod/page/view.php?id=101'));
    }

    /**
     * Moodle instalado em subpasta: https://site/moodle.
     *
     * O host so era reconhecido quando o dominio vinha colado em /mod/ ou
     * /course/. Com subpasta, a URL era lida como caminho relativo e o id
     * de um terceiro site acabava remapeado.
     */
    public function test_host_com_subpasta_e_reconhecido() {
        $plugin = new local_resourcelinkfix_testable_plugin();
        $plugin->set_restore_state(array(
            'cmmap' => array(101 => 201),
            'oldcourseid' => 42, 'newcourseid' => 77,
            'oldwwwroot' => 'https://origem.example.org/moodle',
            'newwwwroot' => 'https://destino.example.net/ead',
        ));
        // Site de origem: host e id trocados juntos.
        $this->assertSame('https://destino.example.net/ead/mod/page/view.php?id=201',
            $plugin->rewrite('https://origem.example.org/moodle/mod/page/view.php?id=101'));
        // Terceiro site com subpasta: nada muda.
        $this->assertSame('https://outro.example.com/moodle/mod/page/view.php?id=101',
            $plugin->rewrite('https://outro.example.com/moodle/mod/page/view.php?id=101'));
        // Subpasta de dois niveis.
        $this->assertSame('https://outro.example.com/lms/moodle/mod/page/view.php?id=101',
            $plugin->rewrite('https://outro.example.com/lms/moodle/mod/page/view.php?id=101'));
    }

    /**
     * Host com porta nao padrao.
     */
    public function test_host_com_porta_e_reconhecido() {
        $plugin = new local_resourcelinkfix_testable_plugin();
        $plugin->set_restore_state(array(
            'cmmap' => array(101 => 201),
            'oldcourseid' => 42, 'newcourseid' => 77,
            'oldwwwroot' => 'http://origem.example.org:8080',
            'newwwwroot' => self::DESTINO,
        ));
        $this->assertSame(self::DESTINO . '/mod/page/view.php?id=201',
            $plugin->rewrite('http://origem.example.org:8080/mod/page/view.php?id=101'));
        // Terceiro site com porta: intacto.
        $this->assertSame('http://outro.example.com:8080/mod/page/view.php?id=101',
            $plugin->rewrite('http://outro.example.com:8080/mod/page/view.php?id=101'));
    }

    /**
     * Texto solto antes de um caminho relativo nao pode virar host.
     */
    public function test_texto_antes_do_caminho_nao_vira_host() {
        $plugin = $this->plugin();
        $this->assertSame('veja em https://x.example.org e depois mod/page/view.php?id=201',
            $plugin->rewrite('veja em https://x.example.org e depois mod/page/view.php?id=101'));
    }

    /**
     * Link montado em tempo de execucao nunca e alterado: o numero nao esta
     * no arquivo, e mexer no que esta em volta quebraria o codigo.
     */
    public function test_link_montado_em_javascript_nao_e_alterado() {
        $plugin = $this->plugin(true);
        $casos = array(
            "var u = 'mod/quiz/view.php?id=' + cmid;",
            'var u = "mod/quiz/view.php?id=" + id;',
            'var u = `mod/quiz/view.php?id=${cmid}`;',
            'var u = "mod/quiz/view.php?id=" + window.cmid;',
        );
        foreach ($casos as $js) {
            $this->assertSame($js, $plugin->rewrite($js), 'nao pode tocar em: ' . $js);
        }
    }

    /**
     * URL literal dentro de .js e reescrita como qualquer outra.
     */
    public function test_link_literal_em_javascript_e_reescrito() {
        $plugin = $this->plugin(true);
        $js = "const links = { \"Questoes\": '" . self::ORIGEM . "/mod/quiz/view.php?id=101' };";
        $esperado = "const links = { \"Questoes\": '" . self::DESTINO . "/mod/quiz/view.php?id=201' };";
        $this->assertSame($esperado, $plugin->rewrite($js));
    }

    /**
     * URL dentro de url(), sintaxe CSS embutida em .js.
     *
     * Forma encontrada em material real: o parentese nao separa o host do
     * caminho, entao o link e reconhecido como qualquer outro.
     */
    public function test_url_em_sintaxe_css_e_reescrita() {
        $plugin = $this->plugin(true);
        $this->assertSame(
            'background: url(' . self::DESTINO . '/mod/resource/view.php?id=201);',
            $plugin->rewrite('background: url(' . self::ORIGEM . '/mod/resource/view.php?id=101);'));
        // De um terceiro site, continua intacta.
        $this->assertSame(
            'background: url(' . self::TERCEIRO . '/mod/resource/view.php?id=101);',
            $plugin->rewrite('background: url(' . self::TERCEIRO . '/mod/resource/view.php?id=101);'));
    }

    /**
     * Uma unica passada: um id ja trocado nao e remapeado de novo.
     */
    public function test_troca_em_uma_unica_passada() {
        $plugin = new local_resourcelinkfix_testable_plugin();
        $plugin->set_restore_state(array(
            'cmmap' => array(10 => 20, 20 => 30),
            'oldcourseid' => 42, 'newcourseid' => 77,
        ));
        $this->assertSame('/mod/page/view.php?id=20', $plugin->rewrite('/mod/page/view.php?id=10'));
        $this->assertSame('/mod/page/view.php?id=30', $plugin->rewrite('/mod/page/view.php?id=20'));
    }

    /**
     * Formas que o plugin nao alcanca, de proposito.
     */
    public function test_formas_fora_do_alcance() {
        $plugin = $this->plugin();
        $casos = array(
            // Id nao e o primeiro parametro.
            '/mod/page/view.php?forceview=1&id=101',
            // Script fora do padrao.
            '/mod/page/report.php?id=101',
            // Id de instancia, nao cmid.
            '/mod/scorm/player.php?a=101',
            '/mod/data/edit.php?d=101',
            // Nao e link de atividade nem de curso.
            self::ORIGEM . '/pluginfile.php/123/mod_resource/content/0/a.pdf',
            // Prefixo colado.
            '/xmod/page/view.php?id=101',
        );
        foreach ($casos as $url) {
            $this->assertSame($url, $plugin->rewrite($url), 'nao pode tocar em: ' . $url);
        }
    }

    /**
     * Ids vizinhos nao se confundem.
     */
    public function test_id_mais_longo_ou_mais_curto_nao_confunde() {
        $plugin = $this->plugin();
        $this->assertSame('/mod/page/view.php?id=1011', $plugin->rewrite('/mod/page/view.php?id=1011'));
        $this->assertSame('/mod/page/view.php?id=10', $plugin->rewrite('/mod/page/view.php?id=10'));
    }

    /**
     * Sem original_wwwroot nao da para saber qual host e o de origem: link
     * absoluto fica intacto, relativo continua sendo corrigido.
     */
    public function test_sem_wwwroot_de_origem_so_o_relativo_e_corrigido() {
        $plugin = new local_resourcelinkfix_testable_plugin();
        $plugin->set_restore_state(array(
            'cmmap' => array(101 => 201),
            'oldcourseid' => 42, 'newcourseid' => 77,
            'oldwwwroot' => '', 'newwwwroot' => self::DESTINO,
        ));
        $this->assertSame(self::ORIGEM . '/mod/page/view.php?id=101',
            $plugin->rewrite(self::ORIGEM . '/mod/page/view.php?id=101'));
        $this->assertSame('../../mod/page/view.php?id=201',
            $plugin->rewrite('../../mod/page/view.php?id=101'));
    }

    /**
     * A trava aceita a troca legitima: so os links mudaram.
     */
    public function test_trava_aceita_mudanca_so_nos_links() {
        $plugin = $this->plugin();
        $old = '<p>Texto</p><a href="../../mod/page/view.php?id=101">A</a><p>Fim</p>';
        $new = '<p>Texto</p><a href="../../mod/page/view.php?id=201">A</a><p>Fim</p>';
        $this->assertTrue($plugin->only_links_differ($old, $new));
    }

    /**
     * A trava barra perda de conteudo em volta do link.
     */
    public function test_trava_barra_perda_de_texto() {
        $plugin = $this->plugin();
        $old = '<p>Texto</p><a href="../../mod/page/view.php?id=101">A</a><p>Fim</p>';
        $casos = array(
            'texto sumiu'   => '<a href="../../mod/page/view.php?id=201">A</a><p>Fim</p>',
            'fim sumiu'     => '<p>Texto</p><a href="../../mod/page/view.php?id=201">A</a>',
            'tudo vazio'    => '',
            'so o link'     => '../../mod/page/view.php?id=201',
            'texto trocado' => '<p>Outro</p><a href="../../mod/page/view.php?id=201">A</a><p>Fim</p>',
        );
        foreach ($casos as $nome => $new) {
            $this->assertFalse($plugin->only_links_differ($old, $new),
                'deveria barrar: ' . $nome);
        }
    }

    /**
     * A trava barra link que aparece ou desaparece.
     */
    public function test_trava_barra_link_a_mais_ou_a_menos() {
        $plugin = $this->plugin();
        $old = '<a href="../../mod/page/view.php?id=101">A</a>';
        $this->assertFalse($plugin->only_links_differ($old,
            '<a href="../../mod/page/view.php?id=201">A</a><a href="../../mod/page/view.php?id=202">B</a>'));
        $this->assertFalse($plugin->only_links_differ($old, '<a href="">A</a>'));
    }

    /**
     * Conteudo sem link nenhum sai igual.
     */
    public function test_conteudo_sem_link_nao_muda() {
        $plugin = $this->plugin();
        $this->assertSame('', $plugin->rewrite(''));
        $this->assertSame('<p>texto</p>', $plugin->rewrite('<p>texto</p>'));
    }
}
