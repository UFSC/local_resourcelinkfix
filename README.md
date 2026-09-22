# local_resourcelinkfix

Corrige, durante o restore, os links para atividades dentro de arquivos HTML de
recursos do tipo **Arquivo** (`mod_resource`). Moodle 3.0+ (PHP 5.4+).

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

### Host quebrado por hifenização ainda é um host

Texto colado de PDF ou Word chega com o domínio partido ao meio:

    https:// exemplo.org/mod/resource/view.php?id=10
    https://exem- plo.org/mod/resource/view.php?id=10
    https://exemplo. org/mod/resource/view.php?id=10

O host é normalizado antes de ser comparado — espaços somem, e o hífen que
vem **seguido de espaço** some junto, por ser hifenização (`exam- ple`).
O hífen legítimo de domínio (`meu-site`) não é seguido de espaço e permanece.

Sem isso, a URL seria lida como caminho **relativo** e o id de um terceiro
site acabaria remapeado — justamente o que a regra abaixo existe para impedir.
Quando a URL quebrada é do site de origem e o id muda, ela sai consertada.

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

*Administração do site > Plugins > Plugins locais > Correção de links em recursos*

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

### Medição em uma instalação real

Números levantados em uma instalação Moodle 3.0 de porte médio, lendo o conteúdo dos arquivos
(não apenas os registros da tabela `files`):

| | |
|---|---|
| Arquivos HTML distintos em `mod_resource` | 4.968 |
| Links de atividade em `href`/`src` | 13.245 |
| Cobertos pelo regex | **13.227 (99,9%)** |
| Com `id` fora da primeira posição | **0** |
| Fora do padrão (`player.php?a=`, `edit.php?d=`) | 9 |
| Arquivos `.js` distintos | 2.214 |
| Deles, com link de atividade | 264 |
| Links dentro de `.js` | 1.833 |
| Desses, URL literal (alcançável) | **1.827 (99,7%)** |
| Montados em tempo de execução | **0** |

Com a opção `.js` ligada, a cobertura passa de 88% para cerca de 99,8% dos links daquele acervo.
Os números valem para **aquele** acervo: o formato dos links depende de como cada equipe escreve
o material. Repita a medição na sua instalação antes de tirar conclusões.
- [x] Arquivo não-HTML na mesma área (`.js`) permanece inalterado
- [x] `sortorder` preservado (o arquivo principal continua sendo o principal)
- [x] `complete.php` tratado como cmid
- [x] Host quebrado por hifenização, do site de origem e de terceiro site
- [x] `.js` desligado: arquivo intacto; ligado: reescrito
- [x] Em `.js`, concatenação e template literal nunca são alterados

## Testes

A suíte é autocontida: não depende de script, container ou estrutura de diretórios de quem a
executa. Em qualquer instalação com o ambiente de testes do Moodle preparado:

    php admin/tool/phpunit/cli/init.php
    vendor/bin/phpunit --testsuite local_resourcelinkfix_testsuite

| Arquivo | Cobre |
|---|---|
| `tests/rewrite_links_test.php` | A reescrita: cmid, curso, `complete.php`, host de origem, terceiro site, host quebrado por hifenização, literal x montado em `.js` |
| `tests/file_selection_test.php` | Quais arquivos entram, e o papel da opção `.js` |
| `tests/restore_test.php` | Integração: backup e restore reais, em curso novo e em curso existente |

`tests/fixtures/testable_plugin.php` é uma subclasse que substitui o construtor — a classe real
só é instanciada pelo Moodle no meio de um restore — e expõe os métodos internos, evitando
Reflection.

Os testes de integração são os que importam para o ponto central do plugin: trocar o hook de
`/module` para `/course` mantém todos os testes unitários verdes e derruba quatro dos de
integração. O bug que motivou este plugin só aparece num restore de verdade.

## Padrão de código

Segue o [Moodle Coding Style](https://moodledev.io/general/development/policies/codingstyle):
identificadores em inglês, 4 espaços de indentação, linhas dentro de 132 colunas, sem `?>` final,
cabeçalho GPL mais docblock com `@package`/`@copyright`/`@license`, e `defined('MOODLE_INTERNAL')`.
Os comentários e o `lang/pt_br` estão em português.

## Limitações

- Só trata `mod_resource`; o `id` precisa ser o primeiro parâmetro da URL
  (`view.php?id=N`, não `view.php?x=1&id=N`).
- Não reescreve `.css`, nem `.js` enquanto a opção correspondente estiver desligada.
- Scripts que usam o id da **instância** em vez do cmid ficam de fora:
  `mod/scorm/player.php?a=N`, `mod/data/edit.php?d=N`. Mapeá-los exigiria o
  mapeamento por módulo, que é outro mecanismo.
- Fora dos links de atividade/curso, o `wwwroot` antigo permanece: um
  `pluginfile.php` ou `/user/view.php` do site de origem não é tocado.
- Links **relativos** para atividade que não veio no backup continuam
  apontando para este site com um id alheio — não há host antigo a preservar.
- Arquivos externos/alias (`is_external_file()`) são ignorados de propósito.
