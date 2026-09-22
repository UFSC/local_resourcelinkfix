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
        if ($new === $old) {
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
    protected function rewrite_links($content) {
        // O host aceita espacos internos: texto colado de PDF chega com o
        // dominio quebrado por hifenizacao ('moo- dle', 'https:// site').
        // Sem isso, a URL seria lida como caminho relativo e o id de um
        // terceiro site acabaria remapeado.
        $pattern = '~(?:(https?://[ \t]*[a-z0-9.\-]+(?:[ \t]+[a-z0-9.\-]+)*)/)?(?<![a-z0-9_])' .
                   '((mod/[a-z0-9_]+/(view|index|complete)|course/view)\.php\?id=)(\d+)(?!\d)~i';

        return preg_replace_callback($pattern, function ($m) {
            $host = isset($m[1]) ? rtrim($m[1], '/') : '';
            // Hifen seguido de espaco e hifenizacao de texto ('exam- ple'):
            // sai junto. Hifen legitimo de dominio ('meu-site') nao e
            // seguido de espaco, entao fica.
            $cleanhost = preg_replace('/-[ \t]+/', '', $host);
            $cleanhost = preg_replace('/[ \t]+/', '', $cleanhost);

            // Host absoluto de um terceiro site: o id pertence a ele, nao a
            // este restore. Remapea-lo apontaria para la com um id daqui, que
            // la e outra atividade. Nao se toca em nada.
            if ($cleanhost !== '' && strcasecmp($cleanhost, $this->oldwwwroot) !== 0) {
                return $m[0];
            }

            $id = (int)$m[5];
            $new = $this->map_id($m[3], $m[4], $id);

            $prefix = ($host === '') ? '' : $host . '/';
            if ($host !== '' && $new !== $id) {
                $prefix = $this->newwwwroot . '/';
            }

            $output = $prefix . $m[2] . $new;
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
