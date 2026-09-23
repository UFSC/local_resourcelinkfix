<!-- sync: README.md sha256=0ae081cf0cb1740f6b155df2efeb39bc9f399e676d63199c62c362ecc4cb97e4 -->
# local_resourcelinkfix

English version: [README.md](README.md)

Corrige, durante o restore, os links para atividades dentro de arquivos HTML de
recursos do tipo **Arquivo** (`mod_resource`). Requer Moodle 3.0 ou superior; testado no
Moodle 3.0 (PHP 5.6) e no 3.8 (PHP 7.4).

## Como funciona

O plugin se conecta ao ponto **`/module`** do restore e implementa
`after_restore_module()`, executado pelo passo `executing_after_restore` da
`restore_final_task` — depois de todas as atividades e antes de
`drop_and_clean_temp_stuff`, com a `backup_ids_temp` ainda disponível.

Para cada `mod_resource` restaurado:

1. Lê o mapa `course_module` (cmid antigo → novo) do restore em andamento,
   ignorando `newitemid = 0` (módulo não restaurado por completo).
2. Nos arquivos `.html`/`.htm` da área `content` (e nos `.js`, se a opção
   estiver ligada), troca:
   - `mod/xxx/view.php?id=CMID` e `mod/xxx/complete.php?id=CMID` → novo cmid
   - `mod/xxx/index.php?id=CURSO` e `course/view.php?id=CURSO` → novo curso
3. Em backup vindo de **outro site**, troca também o `wwwroot` antigo
   (`original_wwwroot`) pelo deste site nos links absolutos.
4. Regrava o conteúdo com `stored_file::replace_file_with()`, preservando o
   registro do arquivo (id, `sortorder`, nome, `timecreated`).

IDs sem mapeamento ficam intactos, e a troca é feita em uma única passada —
um cmid já reescrito não é remapeado.

### Na dúvida sobre a URL, não se toca

O plugin não tenta adivinhar a forma de um endereço. Quando há indício de URL
absoluta — `://`, `//` no início, credencial, ou um último segmento que pareça
domínio — e a base não pode ser confirmada como a do site de origem, o link
fica exatamente como está.

Isso vale inclusive para formas que o plugin não sabe ler: IPv6
(`https://[2001:db8::1]/...`), domínio com underscore, caminho longo, barra
dupla, esquema não-HTTP. A ausência de reconhecimento nunca vira permissão
para remapear.

A regra existe porque o inverso — supor "caminho relativo" sempre que o host
não é reconhecido — faz o id de um link para **outro** Moodle ser trocado pelo
daqui. O link continua abrindo, mas mostra a atividade errada, sem erro visível.
Um link obsoleto é preferível a um silenciosamente errado.

### Link de um terceiro site não é tocado

Se o link absoluto aponta para um host que **não** é o `original_wwwroot`
(por exemplo `https://terceiro.example.com/mod/page/view.php?id=123`),
nem o host nem o id são alterados: aquele id pertence ao outro site, e
remapeá-lo faria o link abrir **outra atividade** lá. Sem `original_wwwroot`
no backup, nenhum link absoluto é tocado — só os relativos.

### O wwwroot só muda junto com o id

O host antigo é trocado **somente quando o id também foi remapeado**. Se a
atividade não veio no backup, o id continua sendo o do site de origem: trocar o
host apontaria para este site com um id alheio, que pode abrir **outra
atividade**. Mantendo o host antigo, o link segue válido no site de origem —
obsoleto é melhor que silenciosamente errado.

Isso importa porque o core só resolve metade do problema: o
`restore_decode_processor` (`restore_plan.class.php:55`) troca o wwwroot nos
**campos de texto** do banco, mas conteúdo de **arquivo** nunca passa por ele.
Num curso restaurado entre sites, `page.content` e `course_sections.summary`
saem com o host certo enquanto os `.html` de `mod_resource` ficam com o antigo.

### Por que `/module` e não `/course`

`restore_course_task::build()` só adiciona o `restore_course_structure_step`
(onde fica o ponto de conexão `/course`) quando o alvo é **curso novo** ou
quando `overwrite_conf` está ligado:

    // backup/moodle2/restore_course_task.class.php
    if ($this->get_target() == backup::TARGET_NEW_COURSE ||
        $this->get_setting_value('overwrite_conf') == true) {
        $this->add_step(new restore_course_structure_step('course_info', 'course.xml'));
    }

Um `after_restore_course()` portanto **nunca roda** ao restaurar em curso
existente nem ao importar atividades. Já o `restore_module_structure_step` é
incondicional em `restore_activity_task::build()` (desde que a configuração
`activities` esteja ligada — sem ela não há atividade a corrigir).

## Configuração

*Administração do site > Plugins > Plugins locais > Correção de links em recursos (restore)*

| Opção | Padrão | O que faz |
|---|---|---|
| **Ativado** (`enabled`) | ligado | Desmarcado, o plugin não faz nada e o restore se comporta como se ele não existisse. |
| **Reescrever também arquivos .js** (`rewritejs`) | desligado | Marcado, os `.js` da área `content` entram na reescrita. Só URLs completas com id numérico são tocadas — links montados em tempo de execução (`'view.php?id=' + cmid`) nunca são alterados. |
| **Modo simulação** (`dryrun`) | desligado | Marcado, apenas registra no log do restore quais arquivos *seriam* reescritos e quantos links cada um tem. Nenhum arquivo é alterado. |

A simulação serve para medir o impacto em um curso real antes de ligar a reescrita: restaure com
ela marcada e leia o log do restore. Recomenda-se usá-la antes de ligar a opção `.js`, que mexe
em **código**: um erro no HTML estraga um link, no `.js` pode quebrar a navegação do recurso.

## Instalação

Copiar a pasta para `local/resourcelinkfix` e rodar a atualização em
*Administração do site > Notificações*.

## Exemplo

O arquivo [`example/navigation.html`](example/navigation.html) traz uma página de navegação com
um caso de cada regra — os reescritos e os preservados, cada um com o comentário do porquê.
Serve de referência e de material para reproduzir os cenários abaixo.

## Cenários verificados no Moodle 3.0.5

- [x] Restaurar como curso novo (`TARGET_NEW_COURSE`)
- [x] Restaurar mesclando em curso existente (`TARGET_EXISTING_ADDING`)
- [x] Restaurar em curso existente apagando o conteúdo (`TARGET_EXISTING_DELETING`)
- [x] Importar atividades de outro curso (`MODE_IMPORT`)
- [x] Links absolutos (`https://.../mod/...`) e relativos (`../../mod/...`)
- [x] URL sem esquema (`//site/mod/...`) e com credencial (`user@site`)
- [x] Host com subpasta (`site/moodle`) e com porta (`site:8080`)
- [x] Host irreconhecível (IPv6, underscore, caminho longo, barra dupla,
      esquema não-HTTP): o id **não** é remapeado
- [x] Link para atividade que NÃO veio no backup (permanece inalterado)
- [x] Recurso com vários arquivos HTML (subpáginas)
- [x] Backup vindo de outro site: `wwwroot` antigo trocado nos links mapeados
- [x] Backup vindo de outro site: link não mapeado mantém o host antigo
- [x] Link para um TERCEIRO site: host e id intactos
- [x] Backup sem `original_wwwroot`: links absolutos intactos
- [x] Desativado: nenhum arquivo é tocado
- [x] Modo simulação: registra a contagem no log e não grava

Os cenários acima foram exercitados com **material de exemplo** construído para cobrir cada
regra, por `backup_controller`/`restore_controller` em um Moodle 3.0.5. Cada comportamento tem
teste próprio, e as regras críticas foram verificadas por mutação — alterando o código de
propósito para confirmar que o teste fica vermelho.

### Meça a sua instalação

O plugin traz a ferramenta que produziu os números abaixo:

    php local/resourcelinkfix/cli/measure_links.php --js

Ela é somente leitura — nenhum arquivo é alterado — e responde, para o seu acervo, quantos links
o plugin alcança, quantos escapam e por quê, e se os links dentro de `.js` são literais ou
montados em tempo de execução. Aceita `--course=ID` para olhar um curso só e `--help` para as
demais opções.

Use-a **antes** de ligar a opção `.js`: ela diz de antemão o que vai ser tocado.

### Medição em uma instalação real

Números de uma instalação Moodle 3.0 de porte médio, lendo o conteúdo dos arquivos (não apenas os
registros da tabela `files`) e varrendo o arquivo inteiro, como o plugin faz:

| | |
|---|---|
| Arquivos HTML distintos em `mod_resource` | 4.968 |
| Deles, com algum link | 2.100 (42,3%) |
| Links de atividade encontrados | 14.628 |
| — destes, apontam para **outro** Moodle | 156 |
| **Links no escopo do plugin** | **14.472** |
| Reescritos | **14.458 (99,90%)** |
| Com `id` fora da primeira posição | **0** |
| Fora do padrão (`edit.php?d=`, `user/view.php?course=`) | 14 (0,10%) |
| | |
| Arquivos `.js` distintos | 2.214 |
| Deles, com link de atividade | 264 (11,9%) |
| Links dentro de `.js` | 1.833 |
| URL literal, alcançável | **1.827 (99,7%)** |
| Montados em tempo de execução | **0** |

Os 156 links para outros Moodles ficam fora do denominador de propósito: o plugin os preserva por
desenho, e contá-los como "não alcançados" seria puni-lo por seguir a própria regra. Ficam
visíveis porque dizem algo sobre o acervo — que ele referencia outras instalações, o que importa
se esses cursos forem migrados um dia.

⚠️ **O que "outro Moodle" significa depende do backup.** A ferramenta compara o host do link com
o `wwwroot` do site onde ela roda; o plugin, durante um restore, compara com o `original_wwwroot`
daquele backup. Os dois coincidem quando backup e restore acontecem no mesmo site. Ao restaurar um
curso vindo de outra instalação, os links daquela instalação deixam de ser "outro Moodle" e passam
a ser reescritos, host e id — então a medição feita aqui subestima o alcance naquele cenário.

Somando HTML e `.js`: dos 16.305 links no escopo, o plugin reescreve **88,7%** com a configuração
padrão e **99,9%** com a opção `.js` ligada.

Os números valem para **aquele** acervo — o formato dos links depende de como cada equipe escreve
o material. Rode `cli/measure_links.php --js` na sua instalação antes de tirar conclusões.

## Testes

A suíte é autocontida: não depende de script, container ou estrutura de diretórios de quem a
executa. Os mesmos 46 testes rodam do Moodle 3.0 ao 3.8.

| Arquivo | Cobre |
|---|---|
| `tests/rewrite_links_test.php` | A reescrita: cmid, curso, `complete.php`, host de origem, terceiro site, host quebrado por hifenização, literal x montado em `.js` |
| `tests/file_selection_test.php` | Quais arquivos entram, e o papel da opção `.js` |
| `tests/restore_test.php` | Integração: backup e restore reais, em curso novo e em curso existente |
| `tests/pcre_limits_test.php` | Limites do PCRE: com a regex abortada, o arquivo é recusado e a trava nega; entradas longas não estouram |

### Integração contínua

A cada push e pull request, o GitHub Actions (`.github/workflows/ci.yml`) testa o plugin num
Moodle limpo:

| Job | Moodle | PHP | O que roda |
|---|---|---|---|
| `versions` | — | — | Escolhe as versões pela branch: `MOODLE_30_STABLE` → 3.0, `MOODLE_38_STABLE` → 3.8; as demais (`main`, branches de trabalho) → `DEFAULT_VERSIONS`, hoje `30 38` |
| `moodle-plugin-ci` | 3.8 | 7.4 | [moodle-plugin-ci](https://moodlehq.github.io/moodle-plugin-ci/) 4.x: PHPUnit, lint, validação, savepoints, Coding Style (phpcs), PHPDoc e phpmd |
| `moodle30` | 3.0 | 5.6 | Só PHPUnit, com o ambiente montado à mão |
| `leiame` | — | — | Este `LEIAME.md` corresponde ao `README.md` atual (hash na primeira linha) |

O moodle-plugin-ci não aceita Moodle anterior ao 3.2 (e a 4.x, anterior ao 3.8.3), por isso o
job do 3.0 não usa a ferramenta. As normas verificadas no 3.8 valem para o 3.0: o código é o
mesmo. phpcs e PHPDoc são bloqueantes — qualquer apontamento falha o job; o phpmd só avisa.

O `README.md`, em inglês, é a fonte; este arquivo é a tradução. Ao mudar o README, traduza a
mudança aqui e atualize o hash da primeira linha (`sha256sum README.md`), senão o job `leiame`
falha.

### Rodar localmente

**Moodle 3.8 ou posterior**, numa instalação com o ambiente de testes preparado:

    php admin/tool/phpunit/cli/init.php
    vendor/bin/phpunit --testsuite local_resourcelinkfix_testsuite

Para verificar também as normas, instale o moodle-plugin-ci e rode os mesmos comandos do job
`moodle38`.

**Moodle 3.0 com PHP 5.6**: o `init.php` não funciona como está. Ele roda
`composer self-update`, que traz o Composer 2.x e aborta no PHP 5.6. O caminho que o CI usa:

    php composer.phar install        # Composer 1.10: o 2.x recusa o "phpunit/dbUnit" do 3.0
    php admin/tool/phpunit/cli/util.php --install
    php admin/tool/phpunit/cli/util.php --buildconfig
    vendor/bin/phpunit --testsuite local_resourcelinkfix_testsuite

O locale `en_AU.UTF-8` precisa estar instalado (`sudo locale-gen en_AU.UTF-8`), senão o PHPUnit
do Moodle recusa o ambiente.

Rodar `vendor/bin/phpunit` apontando para o diretório `tests/` não executa nada: o PHPUnit
procura `*Test.php`, e o Moodle usa `*_test.php`. Use a testsuite.

`tests/fixtures/testable_plugin.php` é uma subclasse que substitui o construtor — a classe real
só é instanciada pelo Moodle no meio de um restore — e expõe os métodos internos, evitando
Reflection.

Os testes de integração são os que importam para o ponto central do plugin: trocar o hook de
`/module` para `/course` mantém todos os testes unitários verdes e derruba quatro dos de
integração. O bug que motivou este plugin só aparece num restore de verdade.

## Padrão de código

A referência é o [Moodle Coding Style](https://moodledev.io/general/development/policies/codingstyle):
identificadores em inglês, 4 espaços de indentação, linhas dentro de 132 colunas, sem `?>` final,
cabeçalho GPL mais docblock com `@package`/`@copyright`/`@license`, e `defined('MOODLE_INTERNAL')`
onde o arquivo tem efeito colateral. Os comentários também são em inglês, como pede o
[checklist de contribuição de plugins](https://moodledev.io/general/community/plugincontribution/checklist);
o phpcs não confere isso, então a revisão confere. O texto que o plugin exibe (inclusive a
ferramenta de linha de comando) vem de `lang/en`, com tradução em `lang/pt_br`.

O phpcs (com moodle-cs) e o PHPDoc são **bloqueantes** no CI: qualquer erro ou aviso falha o
job.

Atender ao Moodle 3.0 (PHP 5.6) e ao moodle-cs ao mesmo tempo impede a desestruturação:
`[$a, $b] = …` exige PHP 7.1, e o moodle-cs proíbe `list()`. Use acesso por índice
(`$a = $par[0];`).

## Limitações

- Só trata `mod_resource`; o `id` precisa ser o primeiro parâmetro da URL
  (`view.php?id=N`, não `view.php?x=1&id=N`).
- Não reescreve `.css`, nem `.js` enquanto a opção correspondente estiver desligada.
- Scripts que usam o id da **instância** em vez do cmid ficam de fora:
  `mod/scorm/player.php?a=N`, `mod/data/edit.php?d=N`. Mapeá-los exigiria o
  mapeamento por módulo, que é outro mecanismo.
- Fora dos links de atividade/curso, o `wwwroot` antigo permanece: um
  `pluginfile.php` ou `/user/view.php` do site de origem não é tocado.
- **Host partido por hifenização não é corrigido.** Texto colado de PDF chega
  com o domínio quebrado (`https:// site`, `exam- ple`, `site. org`). Como o
  espaço impede ler a URL inteira, o link é preservado em vez de adivinhado.
  Medido em uma instalação real: 6 ocorrências em 14.628 links.
- **URL com espaço literal no caminho** (`https://site/pasta com espaco/mod/...`)
  é lida como caminho relativo, e o id pode ser remapeado mesmo sendo de outro
  site. Endereço com espaço é malformado — o correto é `%20`, que o plugin trata
  normalmente. Fechar esse caso faria o plugin deixar de corrigir links
  relativos precedidos de texto com `://`, que são mais comuns.
- Links **relativos** para atividade que não veio no backup continuam
  apontando para este site com um id alheio — não há host antigo a preservar.
- Arquivos externos/alias (`is_external_file()`) são ignorados de propósito.
