<?php

declare(strict_types=1);

namespace ParticleAcademy\FancySchema;

/**
 * Fail-closed JSON Schema 2020-12 validation.
 *
 *     $schema = FancySchema::compile($decoded);     // throws SchemaException if it cannot evaluate it in full
 *     $result = $schema->validate($document);       // Result: valid + errors
 *
 * Decode JSON with objects as objects (`json_decode($json)`, not `, true`) so
 * `{}` and `[]` stay distinct, or use compileJson() / validateJson().
 */
final class FancySchema
{
    /** Compile a decoded schema (stdClass objects, or associative arrays). */
    public static function compile(mixed $schema, ?Limits $limits = null): CompiledSchema
    {
        $limits ??= new Limits;

        return new CompiledSchema(Compiler::compile($schema, $limits), $limits);
    }

    /** Compile a schema from JSON text. Refuses text over Limits::$maxSchemaBytes before decoding it. */
    public static function compileJson(string $json, ?Limits $limits = null): CompiledSchema
    {
        $limits ??= new Limits;

        return self::compile(Json::decodeSchema($json, $limits), $limits);
    }

    /** Compile and validate in one call. Reuse compile() when there are many documents. */
    public static function validate(mixed $schema, mixed $document, ?Limits $limits = null): Result
    {
        return self::compile($schema, $limits)->validate($document);
    }
}
