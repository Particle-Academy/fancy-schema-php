# Using particle-academy/fancy-schema — guide for agents

How an agent installs and uses this package in a consumer app. What the package
is, is in [README.md](./README.md). Notes for working ON this repo's code are in
[CONTRIBUTING.md](./CONTRIBUTING.md).

## Install

```bash
composer require particle-academy/fancy-schema
```

- **Not on Packagist yet.** The command above works once it is published.
- PHP `^8.4` with `ext-json` and `ext-mbstring`. No other dependency, and no
  framework: it works in Laravel and outside it.

## Find it on the Fancy MCP server

Not listed on the Fancy MCP catalog server (`https://ui.particle.academy/mcp`,
MCP id `fancy-ui`) yet; it appears there once published. Look for it later with
`search-backend-packages` (stack `php`). The TypeScript twin is
`@particle-academy/fancy-schema`.

## API essentials

```php
use ParticleAcademy\FancySchema\FancySchema;
use ParticleAcademy\FancySchema\Limits;
use ParticleAcademy\FancySchema\SchemaException;

$schema = FancySchema::compile(json_decode($schemaJson));    // or compileJson($schemaJson)
$result = $schema->validate(json_decode($documentJson));     // or validateJson($documentJson)

$result->valid;      // bool
$result->errors;     // list<ValidationError>: code, keyword, instancePath, schemaPath, message, detail
$result->truncated;  // true when maxErrors cut the list

FancySchema::validate($schema, $document);                   // compile + validate, one call
FancySchema::compile($schema, new Limits(maxSteps: 50_000)); // every limit is a named argument
```

Compile once and reuse the `CompiledSchema` for many documents.

## Traps

- **A schema with any keyword outside 0.1 THROWS** `SchemaException`
  (`reason: unsupported_keyword`), including `pattern`, `anyOf`, `if`/`then`,
  `maxLength`, `minItems` and `format`. The JS package would silently ignore
  them. Catch it at boot, not per request: compile when the app starts, so a
  schema the validator cannot evaluate stops the deploy rather than a user.
- **`$ref` resolves `#/$defs/<name>` only.** A pointer deeper into a
  definition (`#/$defs/a/properties/b`) or anywhere else is `unsupported_ref`.
  Move the target into its own `$defs` entry.
- **Decode with objects** (`json_decode($json)`, not `json_decode($json, true)`).
  With arrays, an empty `{}` in a document becomes `[]` and is read as an
  **array**, so it fails `"type": "object"`. Use `validateJson()` to avoid it. In
  a schema, an empty array is read as the empty schema, which is what it meant.
- **Limits fail closed.** A document past `maxDocumentDepth` (64) or
  `maxDocumentNodes` (100,000) is invalid even against `true`. Raise the limit if
  your documents are legitimately larger.
- **Match on `code`, not `message`.** Messages may be reworded; codes are a
  closed list (`ValidationError::CODES`, `SchemaException::REASONS`).
- **A message never repeats a document value**, so log `instancePath` and
  `detail`. `detail` CAN carry document text: for `additional_property` it is the
  unexpected key.
- **Strings count code points.** `minLength: 1` accepts one emoji.
- **`1.0` is an integer.** JSON has one number type, so a float holding an
  integer satisfies `"type": "integer"` and equals `1` in `const` / `enum`.
