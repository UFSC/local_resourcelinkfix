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
2. Nos arquivos `.html`/`.htm` da área `content`, troca:
   - `mod/xxx/view.php?id=CMID` → novo cmid
   - `mod/xxx/index.php?id=CURSO` e `course/view.php?id=CURSO` → novo curso
3. Em backup vindo de **outro site**, troca também o `wwwroot` antigo
   (`original_wwwroot`) pelo deste site nos links absolutos.
4. Regrava o conteúdo com `stored_file::replace_file_with()`, preservando o
   registro do arquivo (id, `sortorder`, nome, `timecreated`).

IDs sem mapeamento ficam intactos, e a troca é feita em uma única passada —
um cmid já reescrito não é remapeado.

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
| **Modo simulação** (`dryrun`) | desligado | Marcado, apenas registra no log do restore quais arquivos *seriam* reescritos e quantos links cada um tem. Nenhum arquivo é alterado. |

A simulação serve para medir o impacto em um curso real antes de ligar a reescrita: restaure com
ela marcada e leia o log do restore.

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
regra, por `backup_controller`/`restore_controller` em um Moodle 3.0.5. Eles demonstram o
mecanismo; **não** substituem uma medição de cobertura sobre o HTML real de uma instalação, que
depende do formato dos links que cada equipe escreve — ver *Limitações*.
- [x] Arquivo não-HTML na mesma área (`.js`) permanece inalterado
- [x] `sortorder` preservado (o arquivo principal continua sendo o principal)

## Padrão de código

Segue o [Moodle Coding Style](https://moodledev.io/general/development/policies/codingstyle):
identificadores em inglês, 4 espaços de indentação, linhas dentro de 132 colunas, sem `?>` final,
cabeçalho GPL mais docblock com `@package`/`@copyright`/`@license`, e `defined('MOODLE_INTERNAL')`.
Os comentários e o `lang/pt_br` estão em português.

## Limitações

- Só trata `mod_resource`; o `id` precisa ser o primeiro parâmetro da URL
  (`view.php?id=N`, não `view.php?x=1&id=N`).
- Não reescreve links em arquivos `.js` ou `.css`.
- Fora dos links de atividade/curso, o `wwwroot` antigo permanece: um
  `pluginfile.php` ou `/user/view.php` do site de origem não é tocado.
- Links **relativos** para atividade que não veio no backup continuam
  apontando para este site com um id alheio — não há host antigo a preservar.
- Arquivos externos/alias (`is_external_file()`) são ignorados de propósito.
