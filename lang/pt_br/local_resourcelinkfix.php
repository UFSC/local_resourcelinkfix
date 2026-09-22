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
 * Brazilian Portuguese strings for local_resourcelinkfix.
 *
 * @package    local_resourcelinkfix
 * @copyright  2026 UFSC
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'Correção de links em recursos (restore)';
$string['privacy:metadata'] = 'O plugin não armazena dados pessoais.';

$string['enabled'] = 'Ativado';
$string['enabled_desc'] = 'Reescreve, durante o restore, os links para atividades dentro dos
arquivos HTML de recursos do tipo Arquivo (mod_resource). Desativado, o plugin não faz nada e
o restore se comporta exatamente como se ele não existisse.';

$string['rewritejs'] = 'Reescrever também arquivos .js';
$string['rewritejs_desc'] = 'Reescreve os links dentro de arquivos <code>.js</code>, e não só no
HTML. Só URLs completas com id numérico são tocadas, então links montados em tempo de execução
(<code>\'view.php?id=\' + cmid</code>) nunca são alterados. Um .js é código: meça o impacto com o
modo simulação antes de ligar.';

$string['dryrun'] = 'Modo simulação (não grava)';
$string['dryrun_desc'] = 'Apenas registra no log do restore quais arquivos <em>seriam</em>
reescritos e quantos links cada um tem. Nenhum arquivo é alterado. Serve para medir o impacto
em um curso real antes de ligar a reescrita.';

$string['logrewritten'] = 'local_resourcelinkfix: {$a->file} (cmid {$a->cmid}): {$a->links} link(s) reescrito(s)';
$string['logdryrun'] = 'local_resourcelinkfix [SIMULAÇÃO]: {$a->file} (cmid {$a->cmid}): {$a->links} link(s) seriam reescritos';
$string['errorpcre'] = 'O motor de expressões regulares desistiu deste arquivo (erro PCRE {$a}); o arquivo não foi alterado.';

$string['errorcontentlost'] = 'Perda de conteúdo evitada em {$a->file} (cmid {$a->cmid}): a reescrita alterou mais do que os'
    . ' links ({$a->oldsize} bytes antes, {$a->newsize} depois). O arquivo não foi alterado; as duas'
    . ' versões seguem no log.';
