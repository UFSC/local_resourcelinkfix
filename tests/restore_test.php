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
 * Testes de integracao: backup e restore de verdade.
 *
 * @package    local_resourcelinkfix
 * @copyright  2026 UFSC
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_resourcelinkfix;

use advanced_testcase;
use backup;
use backup_controller;
use context_module;
use restore_controller;
use restore_dbops;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');

/**
 * Exercita o plugin pelo caminho real: um restore completo.
 *
 * O ponto de conexao do plugin e /module, e nao /course, porque
 * restore_course_task::build() so adiciona o restore_course_structure_step
 * quando o alvo e curso novo. Estes testes cobrem os dois alvos.
 *
 * @package    local_resourcelinkfix
 * @copyright  2026 UFSC
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @group      local_resourcelinkfix
 */
class restore_test extends advanced_testcase {
    /**
     * Cria um curso com uma atividade e um recurso cujo HTML e cujo JS
     * apontam para ela.
     *
     * @return array [stdClass $course, int $cmid da atividade, int $cmid do recurso]
     */
    protected function criar_curso_de_origem() {
        global $USER;

        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['numsections' => 2]);
        $page = $generator->create_module('page', ['course' => $course->id]);
        $resource = $generator->create_module('resource', ['course' => $course->id]);

        $context = context_module::instance($resource->cmid);
        $fs = get_file_storage();
        $base = ['contextid' => $context->id, 'component' => 'mod_resource',
            'filearea' => 'content', 'itemid' => 0, 'filepath' => '/', 'userid' => $USER->id];

        $fs->create_file_from_string(
            array_merge($base, ['filename' => 'index.html', 'sortorder' => 1]),
            '<a href="../../mod/page/view.php?id=' . $page->cmid . '">Atividade</a>'
        );
        $fs->create_file_from_string(
            array_merge($base, ['filename' => 'nav.js', 'sortorder' => 0]),
            "var u = '../../mod/page/view.php?id={$page->cmid}';"
        );

        return [$course, $page->cmid, $resource->cmid];
    }

    /**
     * Faz o backup de um curso e devolve o diretorio extraido.
     *
     * @param int $courseid
     * @return string
     */
    protected function fazer_backup($courseid) {
        global $USER, $CFG;

        $bc = new backup_controller(
            backup::TYPE_1COURSE,
            $courseid,
            backup::FORMAT_MOODLE,
            backup::INTERACTIVE_NO,
            backup::MODE_GENERAL,
            $USER->id
        );
        $bc->execute_plan();
        $results = $bc->get_results();
        $file = $results['backup_destination'];
        $bc->destroy();

        $dir = 'rlf_' . uniqid();
        $path = $CFG->tempdir . '/backup/' . $dir;
        check_dir_exists($path);
        $file->extract_to_pathname(get_file_packer('application/vnd.moodle.backup'), $path);
        return $dir;
    }

    /**
     * Restores an extracted backup into a course.
     *
     * @param string $dir Diretorio do backup extraido.
     * @param int $destino Curso de destino.
     * @param int $target Constante backup::TARGET_*.
     */
    protected function restaurar($dir, $destino, $target) {
        global $USER;

        $rc = new restore_controller(
            $dir,
            $destino,
            backup::INTERACTIVE_NO,
            backup::MODE_GENERAL,
            $USER->id,
            $target
        );
        $rc->execute_precheck();
        $rc->execute_plan();
        $rc->destroy();
    }

    /**
     * Conteudo de um arquivo do unico mod_resource de um curso.
     *
     * @param int $courseid
     * @param string $filename
     * @return string
     */
    protected function conteudo($courseid, $filename) {
        global $DB;

        $cmid = $DB->get_field_sql(
            "SELECT cm.id
                                      FROM {course_modules} cm
                                      JOIN {modules} m ON m.id = cm.module
                                     WHERE cm.course = ? AND m.name = 'resource'",
            [$courseid],
            IGNORE_MULTIPLE
        );
        $file = get_file_storage()->get_file(
            context_module::instance($cmid)->id,
            'mod_resource',
            'content',
            0,
            '/',
            $filename
        );
        return $file ? $file->get_content() : '';
    }

    /**
     * Cmid da unica atividade page de um curso.
     *
     * @param int $courseid
     * @return int
     */
    protected function cmid_da_page($courseid) {
        global $DB;

        return (int)$DB->get_field_sql(
            "SELECT cm.id
                                          FROM {course_modules} cm
                                          JOIN {modules} m ON m.id = cm.module
                                         WHERE cm.course = ? AND m.name = 'page'",
            [$courseid],
            IGNORE_MULTIPLE
        );
    }

    /**
     * Restaurando como curso novo, o link passa a apontar para o cmid novo.
     */
    public function test_restore_em_curso_novo_reescreve_o_link() {
        global $USER;

        $this->resetAfterTest(true);
        $this->setAdminUser();

        $origin = $this->criar_curso_de_origem();

        $course = $origin[0];

        $cmidantigo = $origin[1];

        $rescmid = $origin[2];
        $dir = $this->fazer_backup($course->id);

        $novoid = restore_dbops::create_new_course(
            'Destino',
            'destino-' . uniqid(),
            $course->category
        );
        $this->restaurar($dir, $novoid, backup::TARGET_NEW_COURSE);

        $cmidnovo = $this->cmid_da_page($novoid);
        $this->assertNotEquals($cmidantigo, $cmidnovo);
        $this->assertContains('view.php?id=' . $cmidnovo, $this->conteudo($novoid, 'index.html'));
        $this->assertNotContains('view.php?id=' . $cmidantigo, $this->conteudo($novoid, 'index.html'));
    }

    /**
     * Restaurando em curso existente o plugin tambem age.
     *
     * Este e o caso que um after_restore_course() nunca alcancaria.
     */
    public function test_restore_em_curso_existente_reescreve_o_link() {
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $origin = $this->criar_curso_de_origem();

        $course = $origin[0];

        $cmidantigo = $origin[1];

        $rescmid = $origin[2];
        $dir = $this->fazer_backup($course->id);

        $destino = $this->getDataGenerator()->create_course(['numsections' => 2]);
        $this->restaurar($dir, $destino->id, backup::TARGET_EXISTING_ADDING);

        $cmidnovo = $this->cmid_da_page($destino->id);
        $this->assertNotEquals($cmidantigo, $cmidnovo);
        $this->assertContains('view.php?id=' . $cmidnovo, $this->conteudo($destino->id, 'index.html'));
    }

    /**
     * Com a configuracao desligada, o .js sai intacto.
     */
    public function test_js_nao_e_tocado_por_padrao() {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        set_config('rewritejs', 0, 'local_resourcelinkfix');

        $origin = $this->criar_curso_de_origem();

        $course = $origin[0];

        $cmidantigo = $origin[1];

        $rescmid = $origin[2];
        $dir = $this->fazer_backup($course->id);

        $novoid = restore_dbops::create_new_course(
            'Destino js off',
            'djsoff-' . uniqid(),
            $course->category
        );
        $this->restaurar($dir, $novoid, backup::TARGET_NEW_COURSE);

        $cmidnovo = $this->cmid_da_page($novoid);
        // O HTML foi corrigido...
        $this->assertContains('view.php?id=' . $cmidnovo, $this->conteudo($novoid, 'index.html'));
        // ...e o .js nao.
        $this->assertContains('view.php?id=' . $cmidantigo, $this->conteudo($novoid, 'nav.js'));
    }

    /**
     * Com a configuracao ligada, o .js tambem e reescrito.
     */
    public function test_js_e_reescrito_quando_ligado() {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        set_config('rewritejs', 1, 'local_resourcelinkfix');

        $origin = $this->criar_curso_de_origem();

        $course = $origin[0];

        $cmidantigo = $origin[1];

        $rescmid = $origin[2];
        $dir = $this->fazer_backup($course->id);

        $novoid = restore_dbops::create_new_course(
            'Destino js on',
            'djson-' . uniqid(),
            $course->category
        );
        $this->restaurar($dir, $novoid, backup::TARGET_NEW_COURSE);

        $cmidnovo = $this->cmid_da_page($novoid);
        $this->assertContains('view.php?id=' . $cmidnovo, $this->conteudo($novoid, 'nav.js'));
        $this->assertNotContains('view.php?id=' . $cmidantigo, $this->conteudo($novoid, 'nav.js'));
    }

    /**
     * Desligado, o plugin nao faz nada: o restore se comporta como se ele
     * nao existisse.
     */
    public function test_desligado_nao_toca_em_nada() {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        set_config('enabled', 0, 'local_resourcelinkfix');

        $origin = $this->criar_curso_de_origem();

        $course = $origin[0];

        $cmidantigo = $origin[1];

        $rescmid = $origin[2];
        $dir = $this->fazer_backup($course->id);

        $novoid = restore_dbops::create_new_course(
            'Destino off',
            'doff-' . uniqid(),
            $course->category
        );
        $this->restaurar($dir, $novoid, backup::TARGET_NEW_COURSE);

        $this->assertContains('view.php?id=' . $cmidantigo, $this->conteudo($novoid, 'index.html'));
    }

    /**
     * O arquivo principal continua sendo o principal depois da reescrita.
     */
    public function test_sortorder_e_preservado() {
        global $DB;

        $this->resetAfterTest(true);
        $this->setAdminUser();

        $origin = $this->criar_curso_de_origem();

        $course = $origin[0];

        $cmidantigo = $origin[1];

        $rescmid = $origin[2];
        $dir = $this->fazer_backup($course->id);

        $novoid = restore_dbops::create_new_course(
            'Destino so',
            'dso-' . uniqid(),
            $course->category
        );
        $this->restaurar($dir, $novoid, backup::TARGET_NEW_COURSE);

        $cmid = $DB->get_field_sql(
            "SELECT cm.id
                                      FROM {course_modules} cm
                                      JOIN {modules} m ON m.id = cm.module
                                     WHERE cm.course = ? AND m.name = 'resource'",
            [$novoid],
            IGNORE_MULTIPLE
        );
        $file = get_file_storage()->get_file(
            context_module::instance($cmid)->id,
            'mod_resource',
            'content',
            0,
            '/',
            'index.html'
        );
        $this->assertEquals(1, $file->get_sortorder());
    }
}
