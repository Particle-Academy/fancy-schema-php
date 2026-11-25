# Contributing to particle-academy/fancy-schema (PHP)

Notes for anyone, human or agent, working ON this package's code. How to USE the
package is in [AGENTS.md](./AGENTS.md); what it is, is in [README.md](./README.md).
`CLAUDE.md` is a symlink to this file, so Claude Code reads these notes when it
works in this repo.

A fail-closed JSON Schema 2020-12 validator, the PHP twin of
`@particle-academy/fancy-schema` (CC-fancy-0068). Process rules live in the
envelope's `RULES.md`.

## Layout

- `src/Json.php` normalises every input into one shape before anything reads
  it: `JsonObject` for objects (stdClass or associative array), lists for lists,
  checked scalars. Depth and node limits are enforced HERE, during the copy, so
  nothing downstream recurses into an unbounded value.
- `src/Compiler.php` checks the whole schema and refuses it, collecting every
  problem. It resolves `$ref`s and rejects same-location cycles.
- `src/Evaluation.php` is one document against one compiled schema. It mirrors
  the JS `validateNode` keyword for keyword, in the same order, so the two give
  the same first error.

## The rules that shape this repo

- **Fail closed, at compile time.** A keyword joins the `switch` in
  `Compiler::schema()` only with its evaluation in `Evaluation::node()`, its
  value check, ported JS cases, and fail-closed cases. Never add a keyword to
  `ANNOTATIONS` unless the spec says it asserts nothing.
- **Parity is with the JS package's behaviour, not its source text.** Where the
  JS one is lenient by design (unknown keywords ignored), this one refuses; that
  is the only intended difference. Any other difference in verdict, instancePath,
  schemaPath or keyword on an in-scope schema is a bug in one of the two.
- **`$ref` sits beside its siblings** (2020-12), and `type` failing returns early,
  as in the JS validator.
- **Probes stop at the first error.** `oneOf` and `not` only need pass or fail, so
  a probe throws `BranchFailed` on its first error. Limits still apply inside a
  probe and end the whole evaluation.
- **Keys are strings.** PHP turns the array key `"0"` into `0`; every reader
  casts back with `(string)`.
- **No third-party runtime dependency, no I/O in `src/`.** PinTest enforces both.

## Testing

```bash
composer update
vendor/bin/pest             # ported JS cases, fail-closed, hostile, corpus
php tests/sabotage.php      # 34 mutations, every one must be CAUGHT
```

- **The shared corpus is read in place** from a fancy-schema checkout:
  `FANCY_SCHEMA_ROOT` in CI (at `ref: Corpus::PINNED_REF`), else the envelope's
  sibling `repos/fancy-schema`. Never copy it here. Move the pin and the
  `ci.yml` ref together; PinTest fails if they disagree. A missing corpus FAILS
  the suite, it never skips.
- **The corpus is refused by 0.1**: it needs nine keywords and six deep-pointer
  refs outside the approved scope. `CorpusTest` asserts that exact list, so
  widening the scope fails it on purpose; then the verdict test starts comparing
  verdicts.
- **Read results from the exit code**, never through a pipe.
