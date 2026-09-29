# Design decisions

## Relative links are rewritten only where a link value starts

**Status:** decided in PR #7 (September 2026). Applies to every branch.

### Decision

A relative link (`../mod/page/view.php?id=N`) is rewritten only when its path starts
exactly where a link value starts:

- in HTML: right after `href=` or `src=` (quoted or not), or a CSS `url(`;
- in a `.js` file (only with the `rewritejs` setting on): also right after a quote that
  opens a string assigned after `=` or `:` — never after `+` (it continues another string),
  nor as an argument or array item, which may be resolved against another base
  (`new URL(path, base)`, `[base, path].join('/')`, `base.concat(path)`);
- at the very start of the text.

CSS `url(` is matched in lower case only, so that JS `new URL(` is not taken for it. The
path itself must be plain (letters, digits, `_ . ~ % -` and `/`). A file that mentions a
base address anywhere — `<base`, `&lt;base`, `createElement('base')` — has no relative link
rewritten: the plugin does not try to tell where that base is or what it says. Anywhere else
the link is left as it is.

Absolute links are unaffected by this rule: they carry their own host, and are rewritten only
when the base is exactly the source site's `wwwroot` (`link_reader::read_absolute_prefix()`).

The rule lives in `classes/link_reader.php` (`opens_value()`, `read_relative_prefix()`).

### Why — what failed before

The plugin used to look at the text glued before a relative path and try to tell whether it
was part of an address. Five review rounds on PR #7, each with tests that failed, found a new
form a browser reads as **another site**, turning a link to another Moodle into a link to an
activity here — the worst failure this plugin can have, because the link still opens and
nobody notices:

1. hyphenation from PDFs: `https:// host/mod/...`, `exam- ple.org/`;
2. line breaks and tabs, which browsers drop from URLs: `https://host/\nmod/...`;
3. HTML entities: `/&Tab;/host/`, `&#47&#47;host/`, `&sol;&sol;host/`;
4. schemes with one slash or none: `https:/host/`, `http:host/`;
5. backslashes and escapes: `https:\\host\`, `\x2f\x2f` in JS, `\00002f` in CSS;
6. a quote or `<` inside a quoted value: `href="https://host/'/../mod/..."`;
7. JS concatenation: `"https://host/" + "mod/..."`;
8. a relative path inside another site's parameter: `...&returnurl=/mod/...`;
9. `<base href="https://host/">`.

Each round was fixed by recognising one more form, and the next round found another. The
text before the path cannot answer "is this an address?" — the answer can depend on a quote
inside the value, on another string in the script, or on the page's `<head>`.

### Alternatives rejected

- **Block-list of address forms** (rounds 1–4): every round added forms; the class never
  closed.
- **Allow-list of the text before the path** (round 5): closed the forms visible in that text,
  not the ones outside it (items 6–9).
- **Parsing the HTML with `DOMDocument`**: sees attributes properly, but not links in CSS or
  JS text, and re-serialises the document — the guard exists precisely to refuse any change
  outside the links.

### Consequences

Relative links in `onclick`, in inline `<script>`, in running text, after a `/` in the same
run (`url(img/a.png)` or an inline image before `url(../mod/...)` in minified CSS), after
`folder/index.php?next=`, or with a backslash are **not** rewritten. They stay as they were:
stale, but pointing where they always pointed. A stale link is better than a silently wrong one.

### Threat model

The plugin protects **content written in good faith** — by teachers, authoring tools,
exported packages — against being silently pointed at the wrong activity. It does not try to
resist content **crafted to fool it**, such as a URL of another site whose path holds a quote
followed by `href=` (`href="https://other/x'href=/../mod/..."`).

The reason is what such content could gain: the rewritten link points to an address of
**another site** with an id from here — an address its author could have written directly.
The plugin grants no access and exposes no page of this site. Chasing crafted forms would be
the same endless pursuit that items 1–9 above describe, where the harm is nil.

Known crafted forms that are rewritten, on purpose left out of scope (review round 6): a
quote, space or `<` followed by `href=`, `src=` or `url(` inside another site's URL
(including in `<meta http-equiv="refresh">`), and a `:` inside a JS string. Also out of reach:
a `<base>` declared in **another** file of the same package — each file is read on its own.

### Extending it safely

To reach a new context, add it as a **value opener** in `opens_value()` or
`lead_opens_value()`, with tests for the context and for the same text where it is not an
opener. Do not go back to reading the text before the path to guess whether it is an address.
If a new case seems to need that, write the case down in this file first.
