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

$string['dryrun'] = 'Modo simulação (não grava)';
$string['dryrun_desc'] = 'Apenas registra no log do restore quais arquivos <em>seriam</em>
reescritos e quantos links cada um tem. Nenhum arquivo é alterado. Serve para medir o impacto
em um curso real antes de ligar a reescrita.';

$string['logrewritten'] = 'local_resourcelinkfix: {$a->file} (cmid {$a->cmid}): {$a->links} link(s) reescrito(s)';
$string['logdryrun'] = 'local_resourcelinkfix [SIMULAÇÃO]: {$a->file} (cmid {$a->cmid}): {$a->links} link(s) seriam reescritos';
