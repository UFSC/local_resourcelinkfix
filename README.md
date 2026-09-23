# local_resourcelinkfix

Versão em português: [LEIAME.md](LEIAME.md)

Fixes, during restore, the links to activities inside HTML files of **File** resources
(`mod_resource`). Requires Moodle 3.0 or later; tested on Moodle 3.0 (PHP 5.6) and 3.8
(PHP 7.4).

## How it works

The plugin hooks into the restore's **`/module`** path and implements
`after_restore_module()`, run by the `executing_after_restore` step of
`restore_final_task` — after all activities and before `drop_and_clean_temp_stuff`, while
`backup_ids_temp` is still available.

For each restored `mod_resource`:

1. Reads the `course_module` map (old cmid → new) of the running restore,
   ignoring `newitemid = 0` (module not fully restored).
2. In the `.html`/`.htm` files of the `content` area (and in `.js` files, if the option is
   on), replaces:
   - `mod/xxx/view.php?id=CMID` and `mod/xxx/complete.php?id=CMID` → new cmid
   - `mod/xxx/index.php?id=COURSE` and `course/view.php?id=COURSE` → new course
3. For a backup from **another site**, also replaces the old `wwwroot`
   (`original_wwwroot`) with this site's in absolute links.
4. Writes the content back with `stored_file::replace_file_with()`, keeping the file record
   (id, `sortorder`, name, `timecreated`).

Unmapped ids stay untouched, and the replacement runs in a single pass — a cmid already
rewritten is not remapped.

### When in doubt about the URL, leave it alone

The plugin does not guess the shape of an address. When there is a hint of an absolute URL —
`://`, a leading `//`, credentials, or a last segment that looks like a domain — and the base
cannot be confirmed as the source site's, the link stays exactly as it is.

This holds even for forms the plugin cannot read: IPv6 (`https://[2001:db8::1]/...`), a domain
with an underscore, a long path, a double slash, a non-HTTP scheme. Failing to recognise an
address never becomes permission to remap it.

The rule exists because the opposite — assuming "relative path" whenever the host is not
recognised — swaps the id of a link to **another** Moodle for one from this site. The link
still opens, but shows the wrong activity, with no visible error. A stale link is better than
a silently wrong one.

### A link to a third site is not touched

If an absolute link points to a host that is **not** the `original_wwwroot` (for example
`https://third.example.com/mod/page/view.php?id=123`), neither the host nor the id is changed:
that id belongs to the other site, and remapping it would make the link open **another
activity** there. Without `original_wwwroot` in the backup, no absolute link is touched — only
relative ones.

### The wwwroot only changes together with the id

The old host is replaced **only when the id was also remapped**. If the activity was not in the
backup, the id is still the source site's: replacing the host would point to this site with a
foreign id, which may open **another activity**. Keeping the old host, the link stays valid on
the source site — stale is better than silently wrong.

This matters because core only solves half the problem: `restore_decode_processor`
(`restore_plan.class.php:55`) replaces the wwwroot in database **text fields**, but **file**
content never goes through it. In a course restored across sites, `page.content` and
`course_sections.summary` come out with the right host while the `mod_resource` `.html` files
keep the old one.

### Why `/module` and not `/course`

`restore_course_task::build()` only adds `restore_course_structure_step` (where the `/course`
hook lives) when the target is a **new course** or when `overwrite_conf` is on:

    // backup/moodle2/restore_course_task.class.php
    if ($this->get_target() == backup::TARGET_NEW_COURSE ||
        $this->get_setting_value('overwrite_conf') == true) {
        $this->add_step(new restore_course_structure_step('course_info', 'course.xml'));
    }

An `after_restore_course()` therefore **never runs** when restoring into an existing course or
importing activities. `restore_module_structure_step`, on the other hand, is unconditional in
`restore_activity_task::build()` (as long as the `activities` setting is on — without it there is
no activity to fix).

## Settings

*Site administration > Plugins > Local plugins > Resource link fix (restore)*

| Option | Default | What it does |
|---|---|---|
| **Enabled** (`enabled`) | on | Unticked, the plugin does nothing and restore behaves as if it did not exist. |
| **Also rewrite .js files** (`rewritejs`) | off | Ticked, `.js` files in the `content` area are rewritten too. Only complete URLs with a numeric id are touched — links built at run time (`'view.php?id=' + cmid`) are never changed. |
| **Simulation mode** (`dryrun`) | off | Ticked, only logs to the restore log which files *would* be rewritten and how many links each has. No file is changed. |

Simulation mode measures the impact on a real course before turning rewriting on: restore with it ticked
and read the restore log. Use it before turning on the `.js` option, which touches **code**: a
mistake in HTML breaks one link; in `.js` it can break the resource's navigation.

## Installation

Copy the folder to `local/resourcelinkfix` and run the upgrade from
*Site administration > Notifications*.

## Example

[`example/navigation.html`](example/navigation.html) is a navigation page with one case of each
rule — the rewritten and the preserved, each with a comment on why. It serves as a reference and
as material to reproduce the scenarios below.

## Scenarios verified on Moodle 3.0.5

- [x] Restore as a new course (`TARGET_NEW_COURSE`)
- [x] Restore merging into an existing course (`TARGET_EXISTING_ADDING`)
- [x] Restore into an existing course, deleting its content (`TARGET_EXISTING_DELETING`)
- [x] Import activities from another course (`MODE_IMPORT`)
- [x] Absolute (`https://.../mod/...`) and relative (`../../mod/...`) links
- [x] Scheme-less URL (`//site/mod/...`) and with credentials (`user@site`)
- [x] Host with a subfolder (`site/moodle`) and with a port (`site:8080`)
- [x] Unrecognisable host (IPv6, underscore, long path, double slash, non-HTTP scheme): the id
      is **not** remapped
- [x] Link to an activity NOT in the backup (left unchanged)
- [x] Resource with several HTML files (subpages)
- [x] Backup from another site: old `wwwroot` replaced in mapped links
- [x] Backup from another site: unmapped link keeps the old host
- [x] Link to a THIRD site: host and id untouched
- [x] Backup without `original_wwwroot`: absolute links untouched
- [x] Disabled: no file is touched
- [x] Simulation mode: logs the count and does not write

The scenarios above were exercised with **sample material** built to cover each rule, through
`backup_controller`/`restore_controller` on Moodle 3.0.5. Each behaviour has its own test, and
the critical rules were verified by mutation — changing the code on purpose to confirm the test
goes red.

### Measure your installation

The plugin ships the tool that produced the numbers below:

    php local/resourcelinkfix/cli/measure_links.php --js

It is read-only — no file is changed — and answers, for your content, how many links the plugin
reaches, how many escape and why, and whether links inside `.js` are literal or built at run
time. It accepts `--course=ID` to look at a single course and `--help` for the other options.

Use it **before** turning on the `.js` option: it tells you in advance what will be touched.

### Measurement on a real installation

Numbers from a medium-sized Moodle 3.0 installation, reading file contents (not only the `files`
table records) and scanning each whole file, as the plugin does:

| | |
|---|---|
| Distinct HTML files in `mod_resource` | 4,968 |
| Of these, with any link | 2,100 (42.3%) |
| Activity links found | 14,628 |
| — of which, pointing to **another** Moodle | 156 |
| **Links in the plugin's scope** | **14,472** |
| Rewritten | **14,458 (99.90%)** |
| With `id` not in first position | **0** |
| Outside the pattern (`edit.php?d=`, `user/view.php?course=`) | 14 (0.10%) |
| | |
| Distinct `.js` files | 2,214 |
| Of these, with an activity link | 264 (11.9%) |
| Links inside `.js` | 1,833 |
| Literal URL, reachable | **1,827 (99.7%)** |
| Built at run time | **0** |

The 156 links to other Moodles are left out of the denominator on purpose: the plugin preserves
them by design, and counting them as "not reached" would penalise it for following its own rule.
They stay visible because they say something about the content — that it references other
installations, which matters if those courses are ever migrated.

⚠️ **What "another Moodle" means depends on the backup.** The tool compares the link's host with
the `wwwroot` of the site where it runs; the plugin, during a restore, compares it with that
backup's `original_wwwroot`. The two match when backup and restore happen on the same site. When
restoring a course from another installation, links to that installation stop being "another
Moodle" and are rewritten, host and id — so the measurement made here underestimates the reach
in that scenario.

Adding HTML and `.js`: of the 16,305 links in scope, the plugin rewrites **88.7%** with the
default settings and **99.9%** with the `.js` option on.

The numbers hold for **that** content — the shape of links depends on how each team writes its
material. Run `cli/measure_links.php --js` on your installation before drawing conclusions.

## Testing

The suite is self-contained: it does not depend on scripts, containers or the directory layout
of whoever runs it. The same 46 tests run from Moodle 3.0 to 3.8.

| File | Covers |
|---|---|
| `tests/rewrite_links_test.php` | Rewriting: cmid, course, `complete.php`, source host, third site, host broken by hyphenation, literal vs built in `.js` |
| `tests/file_selection_test.php` | Which files are included, and the role of the `.js` option |
| `tests/restore_test.php` | Integration: real backup and restore, into a new course and an existing one |
| `tests/pcre_limits_test.php` | PCRE limits: with the regex aborted, the file is refused and the guard says no; long inputs do not blow up |

### Continuous integration

On every push and pull request, GitHub Actions (`.github/workflows/ci.yml`) tests the plugin on a
clean Moodle:

| Job | Moodle | PHP | Runs |
|---|---|---|---|
| `versions` | — | — | Picks versions from the branch: `MOODLE_30_STABLE` → 3.0, `MOODLE_38_STABLE` → 3.8; any other (`main`, work branches) → `DEFAULT_VERSIONS`, currently `30 38` |
| `moodle-plugin-ci` | 3.8 | 7.4 | [moodle-plugin-ci](https://moodlehq.github.io/moodle-plugin-ci/) 4.x: PHPUnit, lint, validate, savepoints, coding style (phpcs), PHPDoc and phpmd |
| `moodle30` | 3.0 | 5.6 | PHPUnit only, with the environment set up by hand |
| `leiame` | — | — | `LEIAME.md` matches the current `README.md` (hash on its first line) |

moodle-plugin-ci does not support Moodle before 3.2 (and 4.x, before 3.8.3), so the 3.0 job does
not use it. The standards checked on 3.8 hold for 3.0: the code is the same. For now phpcs,
PHPDoc and phpmd only warn, without failing the job.

This `README.md` is the source; `LEIAME.md` is its Portuguese translation. When changing this
file, translate the change there and update the hash on its first line, or the `leiame` job
fails.

### Running locally

**Moodle 3.8 or later**, on an installation with the test environment initialised:

    php admin/tool/phpunit/cli/init.php
    vendor/bin/phpunit --testsuite local_resourcelinkfix_testsuite

To check the standards too, install moodle-plugin-ci and run the same commands as the
`moodle-plugin-ci` job.

**Moodle 3.0 on PHP 5.6**: `init.php` does not work as is. It runs `composer self-update`, which
fetches Composer 2.x and aborts on PHP 5.6. The path CI uses:

    php composer.phar install        # Composer 1.10: 2.x rejects 3.0's "phpunit/dbUnit"
    php admin/tool/phpunit/cli/util.php --install
    php admin/tool/phpunit/cli/util.php --buildconfig
    vendor/bin/phpunit --testsuite local_resourcelinkfix_testsuite

The `en_AU.UTF-8` locale must be installed (`sudo locale-gen en_AU.UTF-8`), or Moodle's PHPUnit
refuses the environment.

Pointing `vendor/bin/phpunit` at the `tests/` directory runs nothing: PHPUnit looks for
`*Test.php`, and Moodle uses `*_test.php`. Use the testsuite.

`tests/fixtures/testable_plugin.php` is a subclass that replaces the constructor — the real
class is only instantiated by Moodle in the middle of a restore — and exposes the internal
methods, avoiding Reflection.

The integration tests are the ones that matter for the plugin's central point: switching the hook
from `/module` to `/course` keeps every unit test green and breaks four of the integration ones.
The bug that motivated this plugin only shows up in a real restore.

## Coding style

The reference is the [Moodle Coding Style](https://moodledev.io/general/development/policies/codingstyle):
English identifiers, 4-space indentation, lines within 132 columns, no closing `?>`, GPL header
plus a docblock with `@package`/`@copyright`/`@license`, and `defined('MOODLE_INTERNAL')`.
Comments and `lang/pt_br` are in Portuguese.

Compliance is not complete yet. On 2026-09-23 CI's phpcs reported **332 errors and 14
warnings**, almost all formatting (array syntax, wrapping of long calls, indentation); test
method names are in Portuguese, which phpcs does not detect. A cleanup PR will bring the report
to zero and make phpcs blocking.

## Limitations

- Only handles `mod_resource`; the `id` must be the first URL parameter
  (`view.php?id=N`, not `view.php?x=1&id=N`).
- Does not rewrite `.css`, nor `.js` while the corresponding option is off.
- Scripts that use the **instance** id instead of the cmid are left out:
  `mod/scorm/player.php?a=N`, `mod/data/edit.php?d=N`. Mapping them would need the per-module
  mapping, which is a different mechanism.
- Outside activity/course links, the old `wwwroot` remains: a `pluginfile.php` or
  `/user/view.php` from the source site is not touched.
- **A host split by hyphenation is not fixed.** Text pasted from a PDF arrives with the domain
  broken (`https:// site`, `exam- ple`, `site. org`). Since the space prevents reading the whole
  URL, the link is preserved rather than guessed. Measured on a real installation: 6 occurrences
  in 14,628 links.
- **A URL with a literal space in the path** (`https://site/folder with space/mod/...`) is read
  as a relative path, and the id may be remapped even though it belongs to another site. An
  address with a space is malformed — the correct form is `%20`, which the plugin handles
  normally. Closing this case would stop the plugin from fixing relative links preceded by text
  containing `://`, which are more common.
- **Relative** links to an activity not in the backup keep pointing to this site with a foreign
  id — there is no old host to preserve.
- External files/aliases (`is_external_file()`) are ignored on purpose.
