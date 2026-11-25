<?php

declare(strict_types=1);

use ParticleAcademy\FancySchema\FancySchema;
use ParticleAcademy\FancySchema\Result;
use ParticleAcademy\FancySchema\SchemaException;
use ParticleAcademy\FancySchema\ValidationError;

/**
 * Decode JSON the way a consumer should: objects as objects, so `{}` and `[]`
 * stay distinct.
 */
function j(string $json): mixed
{
    return json_decode($json, false, 512, JSON_THROW_ON_ERROR);
}

function ok(mixed $schema, mixed $document): Result
{
    $r = FancySchema::validate($schema, $document);
    expect($r->valid)->toBeTrue('expected valid, got: '.json_encode(array_map(fn (ValidationError $e) => $e->toArray(), $r->errors)));
    expect($r->errors)->toBe([]);

    return $r;
}

/** @return list<ValidationError> */
function bad(mixed $schema, mixed $document): array
{
    $r = FancySchema::validate($schema, $document);
    expect($r->valid)->toBeFalse('expected invalid, got valid');
    expect($r->errors)->not->toBe([], 'an invalid result must carry at least one error');

    return $r->errors;
}

/** Compile and return the SchemaException, failing the test if none is thrown. */
function refused(mixed $schema): SchemaException
{
    try {
        FancySchema::compile($schema);
    } catch (SchemaException $e) {
        return $e;
    }
    test()->fail('expected the schema to be REFUSED, but it compiled');
}
