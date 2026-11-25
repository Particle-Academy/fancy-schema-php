# Changelog

All notable changes to this project are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

**Pre-1.0, breaking changes land in MINOR releases.** The version number is not
promising otherwise until 1.0.

## [Unreleased]

## [0.1.0] - 2026-11-25

### Added

- The package (CC-fancy-0068): a fail-closed JSON Schema 2020-12 validator, the
  PHP twin of `@particle-academy/fancy-schema`. `FancySchema::compile()` /
  `compileJson()` and `CompiledSchema::validate()` / `validateJson()`, returning
  a `Result` of `ValidationError`s that carry a JSON pointer and a stable `code`.
- Keywords: `$ref` (`#/$defs/<name>`), `type`, `properties`, `required`,
  `additionalProperties`, `items`, `allOf`, `oneOf`, `not`, `const`, `enum`,
  `minimum`, `maximum`, `minLength`, and boolean schemas.
- Fail-closed compilation: any other keyword, a `$ref` outside the root
  `$defs`, an unresolvable `$ref`, a same-location `$ref` cycle, another
  dialect or a malformed keyword value throws `SchemaException`, listing every
  problem.
- `Limits`: schema and document bytes, depth and node count, evaluation depth,
  an evaluation step budget, and an error cap. Each has a secure default and
  each is configurable.
- No runtime dependency beyond PHP `^8.4`, `ext-json` and `ext-mbstring`. Pest
  `^5` as the test dependency.
