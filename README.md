# particle-academy/fancy-schema

A zero-dependency **JSON Schema 2020-12** validator for PHP that **fails closed**:
a schema it cannot evaluate in full is refused, never half-applied. The PHP twin
of [`@particle-academy/fancy-schema`](https://github.com/Particle-Academy/fancy-schema),
with the same verdicts and the same error paths on the keywords both implement.

```bash
composer require particle-academy/fancy-schema
```

```php
use ParticleAcademy\FancySchema\FancySchema;

$schema = FancySchema::compileJson(file_get_contents('plan.schema.json'));

$result = $schema->validateJson($body);
if (! $result->valid) {
    foreach ($result->errors as $e) {
        echo "{$e->instancePath} {$e->code}: {$e->message}\n";   // "/steps/2/id required_missing: ..."
    }
}
```

<!-- fancy-agents-note:start -->
> **For agents.** This package is not listed on the Fancy MCP catalog server
> yet. The server, `https://ui.particle.academy/mcp` (streamable HTTP, no API key; MCP id
> `fancy-ui`), lists the released Fancy packages with install commands and
> docs. How an agent installs and uses this package is in [AGENTS.md](https://github.com/Particle-Academy/fancy-schema-php/blob/main/AGENTS.md).
<!-- fancy-agents-note:end -->

## What it supports (0.1)

`$ref` to `#/$defs/<name>` (including recursion) · `type` (single or a list,
with `integer` distinct from `number`) · `properties` · `required` ·
`additionalProperties` · `items` · `allOf` · `oneOf` · `not` · `const` · `enum` ·
`minimum` · `maximum` · `minLength` · boolean schemas. Annotations (`$schema`,
`$id` at the root, `$comment`, `title`, `description`, `default`, `examples`,
`deprecated`, `readOnly`, `writeOnly`) are accepted and assert nothing.

Further keywords are added when a consumer needs them, each with conformance
cases shared with the JS package.

## Fail-closed

The JS package ignores a keyword it does not know. This one **refuses the
schema** with a `SchemaException`, at compile time, before any document is
judged:

| Refused | `reason` |
|---|---|
| Any keyword not listed above, anywhere in the schema (`pattern`, `anyOf`, `format`, a typo, `x-vendor`...) | `unsupported_keyword` |
| A `$ref` that is not `#/$defs/<name>` (remote, `#`, a deeper pointer, an anchor, percent-encoded) | `unsupported_ref` |
| A `$ref` naming no entry in the root `$defs`, even one no document would reach | `unresolvable_ref` |
| A `$ref` / `allOf` / `oneOf` / `not` loop that consumes none of the document | `ref_cycle` |
| `$schema` naming any dialect but 2020-12 | `unsupported_dialect` |
| A malformed keyword value (`"type": "float"`, `"minLength": -1`, `"items": [...]`) | `invalid_keyword_value` |
| Over a limit | `schema_too_large`, `schema_too_deep` |
| NaN, Infinity, invalid UTF-8, a PHP object | `not_json_value` |
| Not JSON (`compileJson`) | `invalid_json` |

Ignoring an unknown assertion can only make a document *more* valid, and under
`not` it inverts. A refusal names every problem in `$e->problems`, not just the
first.

## Errors

Every `ValidationError` carries the JS shape (`instancePath`, `schemaPath`,
`keyword`, `message`, `detail`) plus a stable `code`:

`type_mismatch` · `required_missing` · `additional_property` · `enum_mismatch` ·
`const_mismatch` · `string_too_short` · `number_below_minimum` ·
`number_above_maximum` · `one_of_none` · `one_of_many` · `not_matched` ·
`false_schema` · and, for limits and input: `document_too_deep` ·
`document_too_large` · `max_depth_exceeded` · `evaluation_budget_exceeded` ·
`not_json_value` · `invalid_json`.

Match on `code`. A `message` never repeats a document value.

## Limits

Every bound is a setting with a secure default; a breach fails closed.

```php
use ParticleAcademy\FancySchema\Limits;

$schema = FancySchema::compile($decoded, new Limits(maxDocumentDepth: 128));
```

| Limit | Default |
|---|---|
| `maxSchemaBytes` / `maxDocumentBytes` (JSON text) | 4 MiB / 8 MiB |
| `maxSchemaDepth` / `maxDocumentDepth` | 64 / 64 |
| `maxSchemaNodes` / `maxDocumentNodes` | 50,000 / 100,000 |
| `maxEvaluationDepth` (recursion through `$ref` and combinators) | 256 |
| `maxSteps` (subschema evaluations per document) | 1,000,000 |
| `maxErrors` (`$result->truncated` says when it cut) | 100 |

## Parity with the JS package

On the keywords both implement, the two gave the same verdict and the same
first error on 40,000 generated schema-and-document pairs, measured once
before 0.1 (that check is not in CI). The JS package's in-scope tests are ported
case for case. fancy-schema's shared conformance corpus is read from a
fancy-schema checkout by this package's suite, never copied. That corpus uses
keywords outside 0.1, so 0.1 refuses it; the suite asserts the exact list.

## Licence

MIT.
