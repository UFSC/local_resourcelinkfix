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

    /**
     * Exercita o caminho de rewrite_file() que decide gravar ou recusar,
     * sem precisar de um stored_file nem de um restore em andamento.
     *
     * Devolve o conteudo que seria gravado, ou lanca a mesma excecao que
     * rewrite_file() lancaria.
     *
     * @param string $old
     * @return string|null Null quando nada seria gravado.
     * @throws moodle_exception Quando o PCRE aborta.
     */
    public function rewrite_file_for_test($old) {
        $new = $this->rewrite_links($old);

        if ($new === null) {
            throw new moodle_exception('errorpcre', 'local_resourcelinkfix', '',
                preg_last_error());
        }
        if ($new === $old) {
            return null;
        }
        if (!$this->only_links_changed($old, $new)) {
            return null;
        }
        return $new;
    }

    /**
     * A trava: o conteudo novo difere do antigo apenas nos links?
     *
     * @param string $old
     * @param string $new
     * @return bool
     */
    public function only_links_differ($old, $new) {
        return $this->only_links_changed($old, $new);
    }
}
