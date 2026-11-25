<?php

declare(strict_types=1);

use ParticleAcademy\FancySchema\FancySchema;
use ParticleAcademy\FancySchema\SchemaException;

/*
 * Fail-closed: a schema this validator cannot evaluate in full is REFUSED, at
 * compile time, before any document is looked at. An unknown keyword is never
 * ignored, because ignoring an assertion can only make a document MORE valid,
 * and under `not` it inverts. Each refusal carries a stable reason and the
 * schema pointer that caused it.
 */

dataset('keywords outside 0.1', [
    // Assertions the JS package implements and 0.1 does not.
    'anyOf' => ['{"anyOf":[{"type":"string"}]}', '/anyOf'],
    'if' => ['{"if":{"type":"string"}}', '/if'],
    'then' => ['{"then":{"type":"string"}}', '/then'],
    'else' => ['{"else":{"type":"string"}}', '/else'],
    'maxLength' => ['{"maxLength":3}', '/maxLength'],
    'pattern' => ['{"pattern":"^a$"}', '/pattern'],
    'exclusiveMinimum' => ['{"exclusiveMinimum":1}', '/exclusiveMinimum'],
    'exclusiveMaximum' => ['{"exclusiveMaximum":1}', '/exclusiveMaximum'],
    'multipleOf' => ['{"multipleOf":2}', '/multipleOf'],
    'minItems' => ['{"minItems":1}', '/minItems'],
    'maxItems' => ['{"maxItems":1}', '/maxItems'],
    'uniqueItems' => ['{"uniqueItems":true}', '/uniqueItems'],
    'minProperties' => ['{"minProperties":1}', '/minProperties'],
    'maxProperties' => ['{"maxProperties":1}', '/maxProperties'],
    'propertyNames' => ['{"propertyNames":{"minLength":1}}', '/propertyNames'],
    'patternProperties' => ['{"patternProperties":{"^x":{}}}', '/patternProperties'],
    // The rest of 2020-12 that changes a verdict.
    'format' => ['{"format":"email"}', '/format'],
    'prefixItems' => ['{"prefixItems":[{}]}', '/prefixItems'],
    'contains' => ['{"contains":{}}', '/contains'],
    'dependentRequired' => ['{"dependentRequired":{"a":["b"]}}', '/dependentRequired'],
    'dependentSchemas' => ['{"dependentSchemas":{"a":{}}}', '/dependentSchemas'],
    'unevaluatedProperties' => ['{"unevaluatedProperties":false}', '/unevaluatedProperties'],
    'unevaluatedItems' => ['{"unevaluatedItems":false}', '/unevaluatedItems'],
    'contentMediaType' => ['{"contentMediaType":"application/json"}', '/contentMediaType'],
    '$anchor' => ['{"$anchor":"a"}', '/$anchor'],
    '$dynamicRef' => ['{"$dynamicRef":"#a"}', '/$dynamicRef'],
    '$dynamicAnchor' => ['{"$dynamicAnchor":"a"}', '/$dynamicAnchor'],
    '$vocabulary' => ['{"$vocabulary":{}}', '/$vocabulary'],
    // Older drafts' spellings, which a 2020-12 validator must not half-honour.
    'definitions' => ['{"definitions":{}}', '/definitions'],
    'dependencies' => ['{"dependencies":{}}', '/dependencies'],
    'additionalItems' => ['{"additionalItems":false}', '/additionalItems'],
    // And words nobody has defined at all.
    'a typo' => ['{"requird":["a"]}', '/requird'],
    'a vendor keyword' => ['{"x-nullable":true}', '/x-nullable'],
]);

test('a keyword 0.1 does not implement is refused, never ignored', function (string $schema, string $pointer) {
    $e = refused(j($schema));
    expect($e->reason)->toBe('unsupported_keyword');
    expect($e->pointer)->toBe('#'.$pointer);
})->with('keywords outside 0.1');

test('the refusal reaches validate() too; a document is never judged by a schema that was refused', function () {
    expect(fn () => FancySchema::validate(j('{"pattern":"^a$"}'), 'b'))->toThrow(SchemaException::class);
});

test('an unknown keyword under not would INVERT, so it is refused there too', function () {
    // Ignored, {"not": {"pattern": "^a$"}} becomes {"not": {}}, which rejects
    // EVERY document -- including the ones the author meant to allow.
    $e = refused(j('{"not":{"pattern":"^a$"}}'));
    expect($e->pointer)->toBe('#/not/pattern');
});

test('an unknown keyword is refused wherever it sits, even in an unreferenced $def', function () {
    expect(refused(j('{"$defs":{"unused":{"format":"uri"}}}'))->pointer)->toBe('#/$defs/unused/format');
    expect(refused(j('{"properties":{"a":{"items":{"maxLength":1}}}}'))->pointer)->toBe('#/properties/a/items/maxLength');
    expect(refused(j('{"oneOf":[{"type":"string"},{"anyOf":[]}]}'))->pointer)->toBe('#/oneOf/1/anyOf');
    expect(refused(j('{"additionalProperties":{"x":1}}'))->pointer)->toBe('#/additionalProperties/x');
});

test('every problem is listed, not just the first', function () {
    $e = refused(j('{"pattern":"a","properties":{"x":{"format":"date"}},"$ref":"#/$defs/missing"}'));
    $all = array_map(fn ($p) => $p['reason'].' '.$p['pointer'], $e->problems);
    expect($all)->toContain('unsupported_keyword #/pattern');
    expect($all)->toContain('unsupported_keyword #/properties/x/format');
    expect($all)->toContain('unresolvable_ref #/$ref');
    expect($e->reason)->toBe($e->problems[0]['reason']);
});

test('an unresolvable $ref is refused at compile time, even where no document would reach it', function () {
    $e = refused(j('{"type":"object","properties":{"rare":{"$ref":"#/$defs/nothere"}}}'));
    expect($e->reason)->toBe('unresolvable_ref');
    expect($e->pointer)->toBe('#/properties/rare/$ref');
    // A $defs that exists but lacks the name is the same refusal.
    $e = refused(j('{"$defs":{"there":true},"$ref":"#/$defs/nothere"}'));
    expect($e->reason)->toBe('unresolvable_ref');
    expect($e->pointer)->toBe('#/$ref');
    // The JS validator reports this per document; it is never a pass in either.
    expect(fn () => FancySchema::validate(j('{"$ref":"#/$defs/nothere"}'), j('{"anything":true}')))->toThrow(SchemaException::class);
});

dataset('refs outside one document\'s $defs', [
    'remote' => ['https://example.test/s.json#/$defs/a'],
    'relative file' => ['other.json'],
    'whole document' => ['#'],
    'not under $defs' => ['#/properties/a'],
    'nested path under a def' => ['#/$defs/a/properties/b'],
    'anchor' => ['#a'],
    'percent-encoded' => ['#/$defs/a%20b'],
    'empty name' => ['#/$defs/'],
    'empty' => [''],
]);

test('a $ref that is not #/$defs/<name> is refused', function (string $ref) {
    $schema = (object) ['$defs' => (object) ['a' => (object) ['type' => 'string'], 'a b' => true], '$ref' => $ref];
    $e = refused($schema);
    expect($e->reason)->toBe('unsupported_ref');
    expect($e->pointer)->toBe('#/$ref');
})->with('refs outside one document\'s $defs');

test('a $ref name is an RFC 6901 token: ~1 and ~0 are unescaped', function () {
    ok(j('{"$defs":{"a/b":{"type":"string"},"c~d":{"type":"integer"}},"properties":{"x":{"$ref":"#/$defs/a~1b"},"y":{"$ref":"#/$defs/c~0d"}}}'), j('{"x":"s","y":1}'));
    expect(refused(j('{"$defs":{"a/b":true},"$ref":"#/$defs/a/b"}'))->reason)->toBe('unsupported_ref');
});

test('$defs is resolved from the ROOT only; a nested $defs is not a ref target', function () {
    expect(refused(j('{"properties":{"a":{"$defs":{"x":true},"$ref":"#/$defs/x"}}}'))->reason)->toBe('unresolvable_ref');
});

dataset('dialects', [
    'draft-07' => ['http://json-schema.org/draft-07/schema#'],
    'draft 2019-09' => ['https://json-schema.org/draft/2019-09/schema'],
    'a custom metaschema' => ['https://example.test/my-meta'],
]);

test('a $schema naming any dialect but 2020-12 is refused', function (string $uri) {
    $e = refused((object) ['$schema' => $uri, 'type' => 'string']);
    expect($e->reason)->toBe('unsupported_dialect');
    expect($e->pointer)->toBe('#/$schema');
})->with('dialects');

test('2020-12 is accepted with or without the empty fragment', function () {
    ok((object) ['$schema' => 'https://json-schema.org/draft/2020-12/schema#', 'type' => 'string'], 'x');
});

test('$id or $schema below the root changes resolution, so it is refused', function () {
    expect(refused(j('{"properties":{"a":{"$id":"https://example.test/a","type":"string"}}}'))->pointer)->toBe('#/properties/a/$id');
    expect(refused(j('{"$defs":{"a":{"$schema":"https://json-schema.org/draft/2020-12/schema"}}}'))->pointer)->toBe('#/$defs/a/$schema');
});

dataset('malformed keyword values', [
    'type is a number' => ['{"type":5}', '/type'],
    'type is an unknown name' => ['{"type":"float"}', '/type'],
    'type list is empty' => ['{"type":[]}', '/type'],
    'type list repeats' => ['{"type":["string","string"]}', '/type'],
    'required is a string' => ['{"required":"a"}', '/required'],
    'required holds a number' => ['{"required":[1]}', '/required'],
    'required repeats' => ['{"required":["a","a"]}', '/required'],
    'properties is a list' => ['{"properties":[{"type":"string"}]}', '/properties'],
    'a property schema is a string' => ['{"properties":{"a":"string"}}', '/properties/a'],
    'items is a list (draft-07 tuple)' => ['{"items":[{"type":"string"}]}', '/items'],
    'additionalProperties is null' => ['{"additionalProperties":null}', '/additionalProperties'],
    'allOf is empty' => ['{"allOf":[]}', '/allOf'],
    'oneOf is not a list' => ['{"oneOf":{"a":{}}}', '/oneOf'],
    'not is a number' => ['{"not":1}', '/not'],
    'enum is not a list' => ['{"enum":"a"}', '/enum'],
    'minimum is a string' => ['{"minimum":"1"}', '/minimum'],
    'maximum is a bool' => ['{"maximum":true}', '/maximum'],
    'minLength is negative' => ['{"minLength":-1}', '/minLength'],
    'minLength is a fraction' => ['{"minLength":1.5}', '/minLength'],
    '$ref is not a string' => ['{"$ref":1}', '/$ref'],
    '$defs is a list' => ['{"$defs":[true]}', '/$defs'],
    'the schema is a string' => ['"string"', ''],
    'the schema is null' => ['null', ''],
]);

test('a keyword with a malformed value is refused, not guessed at', function (string $schema, string $pointer) {
    $e = refused(j($schema));
    expect($e->reason)->toBe('invalid_keyword_value');
    expect($e->pointer)->toBe('#'.$pointer);
})->with('malformed keyword values');

test('minLength 2.0 is a valid non-negative integer, as in JSON', function () {
    ok(j('{"minLength":2.0}'), 'ab');
});

test('compileJson refuses a schema that is not JSON', function () {
    $e = null;
    try {
        FancySchema::compileJson('{"type":');
    } catch (SchemaException $caught) {
        $e = $caught;
    }
    expect($e)->not->toBeNull();
    expect($e->reason)->toBe('invalid_json');
});

test('reasons are a closed, stable set', function () {
    expect(SchemaException::REASONS)->toBe([
        'invalid_json',
        'invalid_keyword_value',
        'not_json_value',
        'ref_cycle',
        'schema_too_deep',
        'schema_too_large',
        'unresolvable_ref',
        'unsupported_dialect',
        'unsupported_keyword',
        'unsupported_ref',
    ]);
});
