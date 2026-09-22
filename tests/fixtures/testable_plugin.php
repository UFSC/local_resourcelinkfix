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
 * Subclasse de teste do plugin de restore.
 *
 * @package    local_resourcelinkfix
 * @copyright  2026 UFSC
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');
require_once($CFG->dirroot .
    '/local/resourcelinkfix/backup/moodle2/restore_local_resourcelinkfix_plugin.class.php');

/**
 * Expoe a logica de reescrita sem exigir um restore em andamento.
 *
 * A classe real so e instanciada pelo Moodle no meio de um restore, com um
 * step e uma task. Aqui o construtor e substituido e o estado e injetado, de
 * modo que a reescrita possa ser exercitada isoladamente. O teste de
 * integracao (restore_test.php) cobre o caminho completo.
 *
 * @package    local_resourcelinkfix
 * @copyright  2026 UFSC
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class local_resourcelinkfix_testable_plugin extends restore_local_resourcelinkfix_plugin {

    /**
     * Sem chamar o construtor da classe pai, que exige o step do restore.
     */
    public function __construct() {
        // Nada a fazer: o estado vem por set_restore_state().
    }

    /**
     * Injeta o estado que o plugin normalmente lê da task e da backup_ids_temp.
     *
     * @param array $state Chaves: cmmap, oldcourseid, newcourseid, oldwwwroot,
     *                     newwwwroot, rewritejs, dryrun.
     */
    public function set_restore_state(array $state) {
        $permitidas = array('cmmap', 'oldcourseid', 'newcourseid', 'oldwwwroot',
            'newwwwroot', 'rewritejs', 'dryrun');
        foreach ($permitidas as $nome) {
            if (array_key_exists($nome, $state)) {
                $this->$nome = $state[$nome];
            }
        }
    }

    /**
     * @param string $content
     * @return string
     */
    public function rewrite($content) {
        return $this->rewrite_links($content);
    }

    /**
     * @param string $filename
     * @return bool
     */
    public function processes($filename) {
        return $this->should_process_file($filename);
    }

    /**
     * Quantos links foram trocados na ultima chamada a rewrite().
     *
     * @return int
     */
    public function get_linkcount() {
        return $this->linkcount;
    }
}
