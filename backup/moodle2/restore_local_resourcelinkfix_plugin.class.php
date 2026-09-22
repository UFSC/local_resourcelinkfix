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
 * Restore plugin for local_resourcelinkfix.
 *
 * Reescreve, no restore, os links para atividades dentro dos arquivos HTML
 * de recursos (mod_resource), usando o mapeamento de IDs do proprio restore.
 * Compativel com PHP 5.4+ / Moodle 3.0+.
 *
 * @package    local_resourcelinkfix
 * @copyright  2026 UFSC
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Reescreve os links para atividades nos HTML de mod_resource durante o restore.
 *
 * @package    local_resourcelinkfix
 * @copyright  2026 UFSC
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class restore_local_resourcelinkfix_plugin extends restore_local_plugin {

    /**
     * Mapa cmid antigo => novo por restore, compartilhado entre as instancias
     * do plugin (ha uma por atividade restaurada).
     *
     * @var array restoreid => array
     */
    protected static $cmmaps = array();

    /** @var array Cmid antigo => cmid novo do restore corrente. */
    protected $cmmap = array();

    /** @var int Id do curso no site de origem. */
    protected $oldcourseid = 0;

    /** @var int Id do curso restaurado aqui. */
    protected $newcourseid = 0;

    /** @var string Wwwroot do site onde o backup foi feito. */
    protected $oldwwwroot = '';

    /** @var string Wwwroot deste site. */
    protected $newwwwroot = '';

    /** @var bool Modo simulacao: mede e registra, nao grava. */
    protected $dryrun = false;

    /** @var int Links trocados no arquivo corrente. */
    protected $linkcount = 0;

    /** @var bool Reescrever tambem arquivos .js. */
    protected $rewritejs = false;

    /**
     * Conexao no ponto /module, e nao em /course.
     *
     * restore_course_task::build() so adiciona o restore_course_structure_step
     * (onde fica o ponto /course) quando o alvo e curso novo ou quando
     * 'overwrite_conf' esta ligado. Logo, um after_restore_course() nunca
     * roda ao restaurar em curso existente nem ao importar atividades.
     * Ja o restore_module_structure_step e incondicional
     * (restore_activity_task::build()), desde que 'activities' esteja ligado.
     *
     * O path nao existe no module.xml: o que interessa e registrar o
     * processing object, porque launch_after_restore_methods() itera sobre
     * os path elements do step.
     *
     * @return restore_path_element[]
     */
    protected function define_module_plugin_structure() {
        return array(
            new restore_path_element('local_resourcelinkfix_module',
                $this->get_pathfor('/resourcelinkfix'))
        );
    }

    /**
     * Nunca chamado (o path nao existe no backup), mas precisa existir:
     * restore_path_element exige o metodo no processing object.
     *
     * @param array|stdClass $data
     */
    public function process_local_resourcelinkfix_module($data) {
        // Nada a restaurar.
    }

    /**
     * Executado pelo passo 'executing_after_restore' da restore_final_task,
     * uma vez por atividade restaurada, depois de todas as atividades e antes
     * de 'drop_and_clean_temp_stuff'. A backup_ids_temp ainda esta disponivel.
     */
    public function after_restore_module() {
        global $CFG;

        if ($this->task->get_modulename() !== 'resource') {
            return;
        }

        // Desligado: o restore se comporta como se o plugin nao existisse.
        // get_config() devolve false quando a configuracao nunca foi gravada.
        // Nesse caso vale o padrao do settings.php, que e ligado.
        $enabled = get_config('local_resourcelinkfix', 'enabled');
        if ($enabled !== false && !$enabled) {
            return;
        }
        $this->dryrun = (bool)get_config('local_resourcelinkfix', 'dryrun');
        $this->rewritejs = (bool)get_config('local_resourcelinkfix', 'rewritejs');

        $this->newcourseid = (int)$this->task->get_courseid();
        $this->oldcourseid = (int)$this->task->get_old_courseid();

        // Backup vindo de outro site: os links absolutos carregam o wwwroot de
        // la. O core troca isso nos campos de texto (restore_decode_processor),
        // mas nunca no conteudo de arquivo.
        $info = $this->task->get_info();
        $this->oldwwwroot = isset($info->original_wwwroot)
            ? rtrim($info->original_wwwroot, '/') : '';
        $this->newwwwroot = rtrim($CFG->wwwroot, '/');

        $this->cmmap = $this->get_cmmap();
        if (empty($this->cmmap)) {
            return;
        }

        $cmid = (int)$this->task->get_moduleid();
        $context = context_module::instance($cmid, IGNORE_MISSING);
        if (!$context) {
            return;
        }

        $fs = get_file_storage();
        $files = $fs->get_area_files($context->id, 'mod_resource', 'content', 0, 'sortorder', false);
        foreach ($files as $file) {
            if (!$this->should_process_file($file->get_filename())) {
                continue;
            }
            // Alias ou arquivo externo: o conteudo pertence a outro lugar.
            if ($file->is_external_file()) {
                continue;
            }
            try {
                $this->rewrite_file($fs, $file);
            } catch (Exception $e) {
                // Um arquivo problematico nao pode abortar o restore inteiro.
                $this->task->get_logger()->process(
                    'local_resourcelinkfix: falha ao reescrever ' .
                    $file->get_filepath() . $file->get_filename() .
                    ' (cmid ' . $cmid . '): ' . $e->getMessage(),
                    backup::LOG_WARNING);
            }
        }
    }

    /**
     * Decide se um arquivo da area content entra na reescrita.
     *
     * HTML sempre. Arquivos .js so quando a configuracao estiver ligada:
     * .js e codigo, e um erro ali quebra a navegacao inteira do recurso,
     * nao um link.
     *
     * @param string $filename
     * @return bool
     */
    protected function should_process_file($filename) {
        if (preg_match('/\.html?$/i', $filename)) {
            return true;
        }
        return $this->rewritejs && (bool)preg_match('/\.js$/i', $filename);
    }

    /**
     * Mapa cmid antigo => novo do restore corrente, lido uma unica vez.
     *
     * newitemid e NOT NULL DEFAULT 0: modulos que nao foram restaurados por
     * completo ficam com 0. Mapea-los reescreveria o link para '?id=0',
     * trocando um link obsoleto por um link quebrado.
     *
     * @return array
     */
    protected function get_cmmap() {
        global $DB;

        $restoreid = $this->task->get_restoreid();
        if (isset(self::$cmmaps[$restoreid])) {
            return self::$cmmaps[$restoreid];
        }

        $records = $DB->get_records_menu('backup_ids_temp',
            array('backupid' => $restoreid, 'itemname' => 'course_module'),
            '', 'itemid, newitemid');
        $map = array();
        foreach ($records as $oldid => $newid) {
            if ($newid > 0) {
                $map[(int)$oldid] = (int)$newid;
            }
        }
        self::$cmmaps[$restoreid] = $map;
        return $map;
    }

    /**
     * O prefixo colado antes do caminho indica uma URL absoluta?
     *
     * Tres perguntas que nao dependem de prever a forma do host, e por isso
     * nao deixam passar forma nova de URL.
     *
     * @param string $prefix
     * @return bool
     */
    protected function looks_absolute($prefix) {
        if (strpos($prefix, '://') !== false) {
            return true;
        }
        if (strpos($prefix, '//') === 0) {
            return true;
        }
        if (strpos($prefix, '@') !== false) {
            return true;
        }
        // Ultimo segmento parece dominio ('ple.com/'). Cobre o caso do host
        // partido por hifenizacao, em que o '://' ficou para tras do espaco
        // e nao entrou no prefixo. Na duvida, tratar como absoluto: o custo
        // de errar para este lado e so deixar de corrigir um link.
        return (bool)preg_match('~\.[a-z]{2,}(?::\d+)?/?$~i', $prefix);
    }

    /**
     * Reduz um prefixo a uma forma comparavel: sem esquema, sem espacos de
     * hifenizacao, sem credencial, sem o lixo que vier antes da URL.
     *
     * Comparar a BASE inteira, e nao so o host, e o que permite reconhecer
     * um Moodle instalado em subpasta - onde o wwwroot e 'site/moodle'.
     *
     * @param string $prefix
     * @return string|null Null quando nao ha base legivel.
     */
    protected function normalize_base($prefix) {
        // Hifenizacao de texto colado de PDF: 'moo- dle', 'https:// site'.
        $clean = preg_replace('/-[ \t]+/', '', $prefix);
        $clean = preg_replace('/[ \t]+/', '', $clean);

        // Corta o que vier antes da URL: 'url(', 'href=', texto.
        $pos = strrpos($clean, '://');
        if ($pos !== false) {
            $start = $pos;
            while ($start > 0 && preg_match('~[a-z0-9+.\-]~i', $clean[$start - 1])) {
                $start--;
            }
            $clean = substr($clean, $start);
        }

        // Tira o esquema e a marca de autoridade, se houver.
        $clean = preg_replace('~^[a-z][a-z0-9+.\-]*:~i', '', $clean);
        $clean = preg_replace('~^//~', '', $clean);
        // Credencial nao faz parte da identidade do site.
        $clean = preg_replace('~^[^/@]*@~', '', $clean);

        return ($clean === '') ? null : $clean;
    }

    /**
     * O prefixo aponta para o site onde o backup foi feito?
     *
     * A comparacao e por inicio de string, com a barra final incluida, de
     * modo que 'origem.org.outro.com/' nao passe por 'origem.org/'.
     *
     * @param string $prefix
     * @return bool
     */
    protected function is_origin_prefix($prefix) {
        if ($this->oldwwwroot === '') {
            return false;
        }

        // Sem a marca de autoridade no prefixo nao da para afirmar que a URL
        // foi lida inteira: pode haver um esquema antes, cortado por espaco
        // de hifenizacao. Afirmar origem nesse caso produziria um endereco
        // com dois esquemas colados.
        if (strpos(preg_replace('/[ \t]+/', '', $prefix), '//') === false) {
            return false;
        }

        $base = $this->normalize_base($prefix);
        $origin = $this->normalize_base($this->oldwwwroot . '/');
        if ($base === null || $origin === null) {
            return false;
        }
        return (strcasecmp(substr($base, 0, strlen($origin)), $origin) === 0);
    }

    /**
     * Troca a autoridade (esquema + host) dentro do prefixo, preservando o
     * que vier antes e o caminho intermediario.
     *
     * @param string $prefix
     * @param string $target Novo wwwroot.
     * @return string
     */
    protected function replace_authority($prefix, $target) {
        // Da autoridade ate o fim do prefixo, tolerando a hifenizacao.
        $pattern = '~(?:[a-z][a-z0-9+.\-]*:)?[ \t]*//.*$~i';
        if (preg_match($pattern, $prefix)) {
            return preg_replace($pattern, $target . '/', $prefix, 1);
        }
        // Host partido sem '//' visivel no prefixo: substitui o trecho final
        // que parece dominio.
        return preg_replace('~[^\s/]*\.[a-z]{2,}(?::\d+)?/?$~i', $target . '/', $prefix, 1);
    }

    /**
     * O host e o do site onde o backup foi feito?
     *
     * URL sem esquema herda o esquema da pagina, entao a comparacao ignora
     * o esquema dos dois lados nesse caso.
     *
     * @param string $host
     * @return bool
     */
    protected function is_origin_host($host) {
        if ($this->oldwwwroot === '') {
            return false;
        }
        $origin = $this->oldwwwroot;
        if (strpos($host, '//') === 0) {
            $origin = preg_replace('~^https?:~i', '', $origin);
        }
        return (strcasecmp($host, $origin) === 0);
    }

    /**
     * Trava de seguranca: o conteudo novo difere do antigo SO nos links?
     *
     * Substitui cada link por um marcador fixo nos dois textos e compara o
     * que sobra. Se o resto nao for identico, alguma coisa fora dos links
     * mudou - texto perdido, arquivo truncado, regex que engoliu demais - e
     * a reescrita daquele arquivo e abandonada.
     *
     * A alternativa seria confiar no regex. Um retorno nulo do PCRE ou um
     * padrao que case mais do que devia grava por cima de material didatico
     * sem deixar rastro, e o original ja foi substituido.
     *
     * @param string $old
     * @param string $new
     * @return bool Falso tambem quando nao foi possivel verificar.
     */
    protected function only_links_changed($old, $new) {
        $marker = "\x00" . 'RLFLINK' . "\x00";
        $pattern = self::get_link_pattern();

        $maskedold = preg_replace($pattern, $marker, $old);
        $maskednew = preg_replace($pattern, $marker, $new);

        // Sem mascara confiavel nao ha verificacao: nega por seguranca.
        if ($maskedold === null || $maskednew === null) {
            return false;
        }
        return $maskedold === $maskednew;
    }

    /**
     * Joga um conteudo no log do restore, em pedacos.
     *
     * O logger de restore nao foi feito para texto longo, entao a saida vai
     * fatiada e com rotulo. O objetivo e permitir reconstruir o que teria
     * sido perdido, nao produzir um diff legivel.
     *
     * @param string $label
     * @param string $content
     */
    protected function log_content($label, $content) {
        // Teto deliberado. Em LOG_ERROR a cadeia de loggers do restore passa
        // por error_log, arquivo e uma linha por INSERT em backup_logs - e,
        // com debugdisplay ligado, ecoa na tela. Um HTML de 4 MB viraria
        // milhares de registros, duas vezes. O que interessa e enxergar o
        // estrago, nao arquivar o documento.
        $limit = 8192;
        $truncated = (strlen($content) > $limit);
        $chunks = str_split(substr($content, 0, $limit), 800);
        $total = count($chunks);
        foreach ($chunks as $i => $chunk) {
            $this->task->get_logger()->process(
                sprintf('local_resourcelinkfix [%s %d/%d] %s', $label, $i + 1, $total, $chunk),
                backup::LOG_ERROR);
        }
        if ($truncated) {
            $this->task->get_logger()->process(
                sprintf('local_resourcelinkfix [%s] ... truncado em %d de %d bytes',
                    $label, $limit, strlen($content)), backup::LOG_ERROR);
        }
    }

    /**
     * Regrava o conteudo preservando o registro do arquivo (id, sortorder,
     * filename, timecreated). replace_file_with() troca so contenthash,
     * filesize, referencefileid e userid. Por isso o arquivo temporario
     * e criado com o mesmo userid.
     *
     * @param file_storage $fs
     * @param stored_file $file
     */
    protected function rewrite_file($fs, $file) {
        $old = $file->get_content();
        $this->linkcount = 0;
        $new = $this->rewrite_links($old);

        // preg_replace_callback() devolve null quando o PCRE aborta, sem
        // lancar nada. Tratar isso como "conteudo novo" gravaria vazio por
        // cima do arquivo, e o original se perderia. A excecao cai no
        // catch de after_restore_module() e vira aviso no log do restore.
        if ($new === null) {
            throw new moodle_exception('errorpcre', 'local_resourcelinkfix', '',
                preg_last_error());
        }

        if ($new === $old) {
            return;
        }

        // Trava: nada alem dos links pode ter mudado. Se mudou, o arquivo
        // fica como esta e os dois conteudos vao para o log, para que dê
        // para ver o que se perderia.
        if (!$this->only_links_changed($old, $new)) {
            $this->task->get_logger()->process(
                get_string('errorcontentlost', 'local_resourcelinkfix', (object)array(
                    'file' => $file->get_filepath() . $file->get_filename(),
                    'cmid' => (int)$this->task->get_moduleid(),
                    'oldsize' => strlen($old),
                    'newsize' => strlen($new),
                )), backup::LOG_ERROR);
            $this->log_content('ANTES', $old);
            $this->log_content('DEPOIS', $new);
            return;
        }

        $a = (object)array(
            'file' => $file->get_filepath() . $file->get_filename(),
            'cmid' => (int)$this->task->get_moduleid(),
            'links' => $this->linkcount,
        );
        $this->task->get_logger()->process(
            get_string($this->dryrun ? 'logdryrun' : 'logrewritten',
                'local_resourcelinkfix', $a), backup::LOG_INFO);

        // Simulacao: mediu, registrou, nao grava.
        if ($this->dryrun) {
            return;
        }

        // Restos de uma execucao interrompida.
        $existing = $fs->get_file($file->get_contextid(), 'local_resourcelinkfix',
            'temp', $file->get_id(), '/', 'rewrite.tmp');
        if ($existing) {
            $existing->delete();
        }

        $tmpfile = $fs->create_file_from_string(array(
            'contextid' => $file->get_contextid(),
            'component' => 'local_resourcelinkfix',
            'filearea' => 'temp',
            'itemid' => $file->get_id(),
            'filepath' => '/',
            'filename' => 'rewrite.tmp',
            'userid' => $file->get_userid(),
        ), $new);

        $file->replace_file_with($tmpfile);
        $file->set_timemodified(time());
        $tmpfile->delete();
    }

    /**
     * Troca IDs em uma unica passada, o que evita remapear um ID ja trocado.
     *
     * Formas tratadas: mod/xxx/view.php?id=CMID vira o novo cmid;
     * mod/xxx/index.php?id=CURSO e course/view.php?id=CURSO viram o novo curso.
     * Links absolutos ou relativos. IDs sem mapeamento ficam intactos.
     *
     * Num backup vindo de outro site, o wwwroot antigo do link absoluto e
     * trocado pelo deste site, mas so quando o id tambem foi remapeado.
     * Se a atividade nao veio no backup, o id continua sendo o de la: trocar
     * o host apontaria para este site com um id alheio, que pode abrir outra
     * atividade. Mantendo o host antigo, o link segue valido no site de origem.
     *
     * @param string $content
     * @return string
     */
    /**
     * O padrao que reconhece um link de atividade ou de curso.
     *
     * Publico porque a ferramenta de medicao (cli/measure_links.php) precisa
     * usar exatamente o mesmo padrao: se os dois divergirem, o relatorio
     * passa a medir algo diferente do que o plugin faz.
     *
     * Grupos: 1 host (opcional), 2 caminho ate '?id=', 3 caminho, 4 script,
     * 5 id. O host aceita espacos internos porque texto colado de PDF chega
     * com o dominio quebrado por hifenizacao ('moo- dle', 'https:// site');
     * sem isso a URL seria lida como caminho relativo e o id de um terceiro
     * site acabaria remapeado.
     *
     * @return string
     */
    public static function get_link_pattern() {
        // Dominio, com porta opcional. Os fragmentos separados por espaco
        // cobrem hifenizacao de texto colado de PDF ('moo- dle'); o limite
        // de 4 e deliberado — com '*' o PCRE recursa uma vez por palavra e
        // um texto longo depois de um 'http://' derruba o processo.
        // Captura o que estiver COLADO antes do caminho, sem tentar adivinhar
        // a forma do host. Quem decide e o callback: se o prefixo tiver
        // indicio de URL absoluta e o host nao puder ser confirmado como o
        // de origem, nada e alterado.
        //
        // Tentar reconhecer o host por regex era o que falhava: cada forma
        // nao prevista - IPv6, underscore, caminho longo, barra dupla - era
        // lida como caminho relativo e tinha o id remapeado, apontando para
        // outro Moodle com um id daqui. O limite de 300 evita backtracking
        // em sequencia longa sem espaco.
        return '~([^\s"\'<>]{0,300})(?<![a-z0-9_])' .
               '((mod/[a-z0-9_]+/(view|index|complete)|course/view)\.php\?id=)(\d+)(?!\d)~i';
    }

    /**
     * Troca IDs em uma unica passada, o que evita remapear um ID ja trocado.
     *
     * Formas tratadas: mod/xxx/view.php?id=CMID e mod/xxx/complete.php?id=CMID
     * viram o novo cmid; mod/xxx/index.php?id=CURSO e course/view.php?id=CURSO
     * viram o novo curso. Links absolutos ou relativos. IDs sem mapeamento
     * ficam intactos.
     *
     * Num backup vindo de outro site, o wwwroot antigo do link absoluto e
     * trocado pelo deste site, mas so quando o id tambem foi remapeado.
     * Se a atividade nao veio no backup, o id continua sendo o de la: trocar
     * o host apontaria para este site com um id alheio, que pode abrir outra
     * atividade. Mantendo o host antigo, o link segue valido no site de origem.
     *
     * @param string $content
     * @return string
     */
    protected function rewrite_links($content) {
        $pattern = self::get_link_pattern();
        // A partir daqui o padrao e o mesmo usado por only_links_changed().

        return preg_replace_callback($pattern, function ($m) {
            $prefix = $m[1];

            // Na duvida, nao mexer. Se ha indicio de URL absoluta e o host
            // nao pode ser confirmado como o de origem, o link fica como
            // esta. Um link obsoleto e melhor que um que aponta para outro
            // Moodle com um id daqui e abre a atividade errada em silencio.
            if ($this->looks_absolute($prefix) && !$this->is_origin_prefix($prefix)) {
                return $m[0];
            }

            $id = (int)$m[5];
            $new = $this->map_id($m[3], $m[4], $id);

            $output = $prefix . $m[2] . $new;
            if ($new !== $id && $this->looks_absolute($prefix)) {
                // Host de origem, id remapeado: a autoridade tambem passa a
                // ser a deste site. So ela e trocada - o que vier antes no
                // prefixo ('url(', por exemplo) e preservado.
                $target = $this->newwwwroot;
                if (preg_match('~^[ \t]*//~', $prefix)) {
                    // Sem esquema: a forma e preservada, herda o da pagina.
                    $target = preg_replace('~^https?:~i', '', $target);
                }
                $output = $this->replace_authority($prefix, $target) . $m[2] . $new;
            }

            if ($output !== $m[0]) {
                $this->linkcount++;
            }
            return $output;
        }, $content);
    }

    /**
     * Devolve o id de destino de um link.
     *
     * @param string $path Trecho do caminho, como 'mod/page/view' ou 'course/view'.
     * @param string $script Nome do script, 'view' ou 'index'.
     * @param int $id Id citado no link.
     * @return int
     */
    protected function map_id($path, $script, $id) {
        // O index.php de um modulo recebe o id do curso, nao um cmid.
        $iscourse = (stripos($path, 'course/') === 0) || (strtolower($script) === 'index');
        if ($iscourse) {
            return ($id === $this->oldcourseid) ? $this->newcourseid : $id;
        }
        return isset($this->cmmap[$id]) ? $this->cmmap[$id] : $id;
    }
}
