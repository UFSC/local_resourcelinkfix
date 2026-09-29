# Design decisions

## Relative links are rewritten only where a link value starts

**Status:** decided in PR #7 (September 2026). Applies to every branch.

### Decision

A relative link (`../mod/page/view.php?id=N`) is rewritten only when its path starts
exactly where a link value starts:

- in HTML: right after `href=` or `src=` (quoted or not), or a CSS `url(`;
- in a `.js` file (only with the `rewritejs` setting on): also right after a quote that
  opens a string starting a value — after `=`, `(`, `,`, `:` or `[` — never after `+`;
- at the very start of the text.

The path itself must be plain (letters, digits, `_ . ~ % -` and `/`), and a file that
declares `<base href>` has no relative link rewritten. Anywhere else the link is left as it
is.

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

### Extending it safely

To reach a new context, add it as a **value opener** in `opens_value()` or
`lead_opens_value()`, with tests for the context and for the same text where it is not an
opener. Do not go back to reading the text before the path to guess whether it is an address.
If a new case seems to need that, write the case down in this file first.
