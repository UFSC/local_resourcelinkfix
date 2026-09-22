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
 * Mede quantos links de atividade existem nos recursos e quantos o plugin
 * alcanca. Somente leitura: nenhum arquivo e alterado.
 *
 * @package    local_resourcelinkfix
 * @copyright  2026 UFSC
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');
require_once($CFG->dirroot .
    '/local/resourcelinkfix/backup/moodle2/restore_local_resourcelinkfix_plugin.class.php');

list($options, $unrecognized) = cli_get_params(
    array('help' => false, 'course' => 0, 'examples' => 5, 'js' => false),
    array('h' => 'help', 'c' => 'course', 'e' => 'examples', 'j' => 'js')
);

if ($unrecognized) {
    cli_error(get_string('cliunknowoption', 'admin', implode("\n  ", $unrecognized)));
}

if ($options['help']) {
    echo "
Mede os links de atividade dentro dos recursos do tipo Arquivo (mod_resource)
e informa quantos o local_resourcelinkfix alcanca. Nao altera nada.

Serve para decidir, com numero em vez de suposicao, se vale ligar a opcao de
reescrever arquivos .js, e para saber de antemao o que ficara de fora.

Opcoes:
  -h, --help          Mostra esta ajuda.
  -c, --course=ID     Limita a um curso. Sem isso, varre o site inteiro.
  -e, --examples=N    Quantos exemplos mostrar de cada caso (padrao 5).
  -j, --js            Inclui a analise dos arquivos .js.

Exemplos:
  php local/resourcelinkfix/cli/measure_links.php
  php local/resourcelinkfix/cli/measure_links.php --js
  php local/resourcelinkfix/cli/measure_links.php --course=42 --js -e 10
";
    exit(0);
}

$courseid = (int)$options['course'];
$maxexamples = max(0, (int)$options['examples']);
$withjs = !empty($options['js']);

// O mesmo padrao que o plugin usa para reescrever.
$pattern = restore_local_resourcelinkfix_plugin::get_link_pattern();
// Padrao largo: qualquer link para um script do Moodle, alcancavel ou nao.
$anylink = '~(?:https?://[a-z0-9.\-]+)?/?((?:mod/[a-z0-9_]+/[a-z0-9_]+|course/view|user/view)\.php)\?([^"\'\s>]*)~i';

/**
 * Devolve os arquivos da area content de mod_resource que interessam.
 *
 * @param string $like Filtro SQL para o nome do arquivo.
 * @param int $courseid Zero para o site inteiro.
 * @return array
 */
function local_resourcelinkfix_fetch_files($like, $courseid) {
    global $DB;

    $params = array('like1' => $like);
    $where = "f.component = 'mod_resource' AND f.filearea = 'content'
              AND f.filesize > 0 AND " . $DB->sql_like('f.filename', ':like1', false);
    $from = "{files} f";
    if ($courseid) {
        $from .= " JOIN {context} ctx ON ctx.id = f.contextid AND ctx.contextlevel = :ctxmod
                   JOIN {course_modules} cm ON cm.id = ctx.instanceid AND cm.course = :courseid";
        $params['ctxmod'] = CONTEXT_MODULE;
        $params['courseid'] = $courseid;
    }
    return $DB->get_records_sql("SELECT f.id, f.contenthash, f.filename FROM {$from} WHERE {$where}",
        $params);
}

/**
 * Le o conteudo, pulando o que estiver repetido ou ausente do filedir.
 *
 * @param array $records
 * @param array $seen Hashes ja vistos, por referencia.
 * @param int $missing Contador de ausentes, por referencia.
 * @return array contenthash => conteudo
 */
function local_resourcelinkfix_read_contents($records, &$seen, &$missing) {
    $fs = get_file_storage();
    $out = array();
    foreach ($records as $record) {
        if (isset($seen[$record->contenthash])) {
            continue;
        }
        $file = $fs->get_file_by_id($record->id);
        if (!$file) {
            continue;
        }
        try {
            $out[$record->contenthash] = $file->get_content();
        } catch (Exception $e) {
            $missing++;
            continue;
        }
        $seen[$record->contenthash] = true;
    }
    return $out;
}

/**
 * Imprime uma linha de contagem com percentual.
 *
 * @param string $label
 * @param int $value
 * @param int $total
 */
function local_resourcelinkfix_line($label, $value, $total) {
    $pct = $total > 0 ? sprintf('  (%5.1f%%)', 100 * $value / $total) : '';
    printf("  %-42s %7d%s\n", $label, $value, $pct);
}

$seen = array();
$missing = 0;

cli_heading('Recursos analisados' . ($courseid ? " (curso {$courseid})" : ' (site inteiro)'));

// ----------------------------------------------------------------- HTML ---
$htmlrecords = array_merge(
    local_resourcelinkfix_fetch_files('%.html', $courseid),
    local_resourcelinkfix_fetch_files('%.htm', $courseid)
);
$htmlfiles = local_resourcelinkfix_read_contents($htmlrecords, $seen, $missing);

$stats = array('total' => 0, 'covered' => 0, 'idnotfirst' => 0, 'otherscript' => 0);
$scripts = array();
$hosts = array();
$escaped = array();
$withlinks = 0;

foreach ($htmlfiles as $content) {
    if (!preg_match_all('~(?:href|src)\s*=\s*["\']([^"\']+)["\']~i', $content, $attrs)) {
        continue;
    }
    $found = false;
    foreach ($attrs[1] as $url) {
        if (!preg_match($anylink, $url, $parts)) {
            continue;
        }
        $found = true;
        $stats['total']++;
        $script = strtolower(ltrim($parts[1], '/'));
        $scripts[$script] = isset($scripts[$script]) ? $scripts[$script] + 1 : 1;
        $host = preg_match('~^(https?://[a-z0-9.\-]+)/~i', $url, $h)
            ? strtolower($h[1]) : '(relativo)';
        $hosts[$host] = isset($hosts[$host]) ? $hosts[$host] + 1 : 1;

        if (preg_match($pattern, $url)) {
            $stats['covered']++;
            continue;
        }
        if (preg_match('~(^|&|&amp;)id=\d+~i', $parts[2])) {
            $stats['idnotfirst']++;
            $key = 'id fora da 1a posicao';
        } else {
            $stats['otherscript']++;
            $key = 'script fora do padrao';
        }
        if (!isset($escaped[$key])) {
            $escaped[$key] = array();
        }
        if (count($escaped[$key]) < $maxexamples) {
            $escaped[$key][] = $url;
        }
    }
    if ($found) {
        $withlinks++;
    }
}

local_resourcelinkfix_line('arquivos HTML distintos', count($htmlfiles), 0);
local_resourcelinkfix_line('deles, com algum link', $withlinks, count($htmlfiles));
echo "\n";
cli_heading('Links em HTML');
local_resourcelinkfix_line('total', $stats['total'], 0);
local_resourcelinkfix_line('alcancados pelo plugin', $stats['covered'], $stats['total']);
local_resourcelinkfix_line('escapam: id fora da 1a posicao', $stats['idnotfirst'], $stats['total']);
local_resourcelinkfix_line('escapam: script fora do padrao', $stats['otherscript'], $stats['total']);

if ($scripts) {
    echo "\n  scripts mais linkados:\n";
    arsort($scripts);
    $shown = 0;
    foreach ($scripts as $name => $count) {
        printf("    %7d  %s\n", $count, $name);
        if (++$shown >= 10) {
            break;
        }
    }
}
if ($hosts) {
    echo "\n  hosts:\n";
    arsort($hosts);
    foreach ($hosts as $name => $count) {
        printf("    %7d  %s\n", $count, $name);
    }
}
foreach ($escaped as $key => $examples) {
    echo "\n  exemplos, {$key}:\n";
    foreach ($examples as $url) {
        echo '    ' . substr($url, 0, 110) . "\n";
    }
}

// ------------------------------------------------------------------- JS ---
if ($withjs) {
    echo "\n";
    cli_heading('Arquivos .js');

    $jsrecords = local_resourcelinkfix_fetch_files('%.js', $courseid);
    $jsfiles = local_resourcelinkfix_read_contents($jsrecords, $seen, $missing);

    // Literal: a URL inteira, com id numerico, entre aspas.
    $literal = '~["\'][^"\']*(?:mod/[a-z0-9_]+/(?:view|index|complete)|course/view)\.php\?id=\d+[^"\']*["\']~i';
    // Montado: apos 'id=' vem concatenacao, template ou variavel.
    $built = '~(?:mod/[a-z0-9_]+/(?:view|index|complete)|course/view)\.php\?id=(?:["\']\s*[+.]|\$\{|[a-z_$])~i';
    $any = '~(?:mod/[a-z0-9_]+/(?:view|index|complete)|course/view)\.php\?id=~i';

    $jswith = 0;
    $jstotal = 0;
    $jsliteral = 0;
    $jsbuilt = 0;
    $jssamples = array();

    foreach ($jsfiles as $content) {
        $occurrences = preg_match_all($any, $content, $ignored);
        if (!$occurrences) {
            continue;
        }
        $jswith++;
        $jstotal += $occurrences;
        $jsliteral += preg_match_all($literal, $content, $ignored);
        $jsbuilt += preg_match_all($built, $content, $ignored);
        if (count($jssamples) < $maxexamples
                && preg_match('~[^\s"\']*(?:mod/[a-z0-9_]+/(?:view|index|complete)|course/view)\.php\?id=\d+~i',
                    $content, $sample)) {
            $jssamples[] = $sample[0];
        }
    }

    local_resourcelinkfix_line('arquivos .js distintos', count($jsfiles), 0);
    local_resourcelinkfix_line('deles, com link de atividade', $jswith, count($jsfiles));
    echo "\n";
    local_resourcelinkfix_line('ocorrencias do padrao', $jstotal, 0);
    local_resourcelinkfix_line('URL literal (alcancavel)', $jsliteral, $jstotal);
    local_resourcelinkfix_line('montado em tempo de execucao', $jsbuilt, $jstotal);
    local_resourcelinkfix_line('nao classificado', max(0, $jstotal - $jsliteral - $jsbuilt), $jstotal);

    if ($jssamples) {
        echo "\n  exemplos:\n";
        foreach ($jssamples as $sample) {
            echo '    ' . substr($sample, 0, 110) . "\n";
        }
    }

    echo "\n  Links em .js so sao reescritos com a opcao 'Reescrever tambem arquivos .js'\n";
    echo "  ligada, e apenas os literais. Meca primeiro com o modo simulacao.\n";
}

if ($missing) {
    echo "\n";
    cli_problem("{$missing} arquivo(s) sem conteudo no filedir foram ignorados.\n" .
        "Isso acontece quando o banco veio de outra instalacao sem o moodledata:\n" .
        "a medicao entao cobre apenas parte do acervo.");
}

echo "\n";
