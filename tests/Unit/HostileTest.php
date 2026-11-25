<?php

declare(strict_types=1);

use ParticleAcademy\FancySchema\FancySchema;
use ParticleAcademy\FancySchema\Limits;

/*
 * Hostile schemas and hostile documents. Every bound here is a Limits setting
 * with a secure default, and every breach fails CLOSED: a schema is refused, a
 * document is invalid. Nothing here may hang, exhaust memory or come back valid.
 */

function nested(int $levels, string $leaf = '1'): string
{
    return str_repeat('[', $levels).$leaf.str_repeat(']', $levels);
}

// --- hostile schemas -------------------------------------------------------

test('a $ref cycle that consumes no input is refused at compile time', function () {
    // Without this, {"$ref": "#/$defs/a"} -> a -> a ... recurses at ONE place
    // in the document and only a depth limit would ever stop it.
    expect(refused(j('{"$defs":{"a":{"$ref":"#/$defs/a"}},"$ref":"#/$defs/a"}'))->reason)->toBe('ref_cycle');
    expect(refused(j('{"$defs":{"a":{"$ref":"#/$defs/b"},"b":{"allOf":[{"$ref":"#/$defs/a"}]}}}'))->reason)->toBe('ref_cycle');
    expect(refused(j('{"$defs":{"a":{"oneOf":[{"type":"string"},{"$ref":"#/$defs/a"}]}},"$ref":"#/$defs/a"}'))->reason)->toBe('ref_cycle');
    expect(refused(j('{"$defs":{"a":{"not":{"$ref":"#/$defs/a"}}},"$ref":"#/$defs/a"}'))->reason)->toBe('ref_cycle');
});

test('recursion that steps INTO the document is not a cycle', function () {
    // properties / items / additionalProperties each move one level down the
    // document, so the recursion ends when the document does.
    ok(j('{"$defs":{"t":{"type":"object","properties":{"c":{"$ref":"#/$defs/t"}},"additionalProperties":{"$ref":"#/$defs/t"}}},"$ref":"#/$defs/t"}'), j('{"c":{"c":{}},"d":{}}'));
    ok(j('{"$defs":{"l":{"type":"array","items":{"$ref":"#/$defs/l"}}},"$ref":"#/$defs/l"}'), [[[], [[]]]]);
});

test('a schema nested past maxSchemaDepth is refused', function () {
    $deep = str_repeat('{"not":', 100).'{}'.str_repeat('}', 100);
    expect(refused(j($deep))->reason)->toBe('schema_too_deep');
    // Inside the bound it compiles.
    FancySchema::compile(j(str_repeat('{"not":', 30).'{}'.str_repeat('}', 30)));
});

test('a schema larger than maxSchemaNodes is refused, counting const and enum values too', function () {
    $wide = (object) ['enum' => range(1, 60_000)];
    expect(refused($wide)->reason)->toBe('schema_too_large');
    $props = new stdClass;
    for ($i = 0; $i < 60_000; $i++) {
        $props->{'p'.$i} = true;
    }
    expect(refused((object) ['properties' => $props])->reason)->toBe('schema_too_large');
});

test('schema JSON over maxSchemaBytes is refused before it is decoded', function () {
    $e = null;
    try {
        FancySchema::compileJson('{"description":"'.str_repeat('a', 100).'"}', new Limits(maxSchemaBytes: 64));
    } catch (ParticleAcademy\FancySchema\SchemaException $caught) {
        $e = $caught;
    }
    expect($e?->reason)->toBe('schema_too_large');
});

test('a schema containing a non-JSON PHP value is refused', function () {
    expect(refused((object) ['const' => NAN])->reason)->toBe('not_json_value');
    expect(refused((object) ['const' => INF])->reason)->toBe('not_json_value');
    expect(refused((object) ['const' => new DateTimeImmutable])->reason)->toBe('not_json_value');
    expect(refused((object) ['enum' => [fopen('php://memory', 'r')]])->reason)->toBe('not_json_value');
    expect(refused((object) ['minimum' => NAN])->reason)->toBe('not_json_value');
});

test('maxSteps counts every subschema evaluated, exactly', function () {
    // Root + three items = 4 steps. Pinned exactly, so the budget cannot drift
    // by a factor without a test noticing. Runs before the blow-up below on
    // purpose: with the budget gone, that one would never finish.
    $schema = j('{"items":{"type":"integer"}}');
    expect(FancySchema::compile($schema, new Limits(maxSteps: 4))->validate([1, 2, 3])->valid)->toBeTrue();
    $r = FancySchema::compile($schema, new Limits(maxSteps: 3))->validate([1, 2, 3]);
    expect($r->valid)->toBeFalse();
    expect($r->errors[0]->code)->toBe('evaluation_budget_exceeded');
    expect($r->errors[0]->instancePath)->toBe('/2');
});

test('an exponential oneOf blow-up stops at the evaluation budget, invalid', function () {
    // Every level is a oneOf of two branches that each re-enter the next level
    // through `items`, so the work doubles per level of document.
    $schema = j('{"$defs":{"x":{"oneOf":[{"type":"array","items":{"$ref":"#/$defs/x"}},{"type":"array","items":{"$ref":"#/$defs/x"}}]}},"$ref":"#/$defs/x"}');
    $doc = json_decode(nested(40, '[]'));
    $start = microtime(true);
    $r = FancySchema::compile($schema, new Limits(maxSteps: 20_000))->validate($doc);
    expect(microtime(true) - $start)->toBeLessThan(5.0);
    expect($r->valid)->toBeFalse();
    expect($r->errors[array_key_last($r->errors)]->code)->toBe('evaluation_budget_exceeded');
});

// --- hostile documents -----------------------------------------------------

test('a document nested past maxDocumentDepth is invalid, never valid', function () {
    $r = FancySchema::validate(true, json_decode(nested(100), false, 512));
    expect($r->valid)->toBeFalse();
    expect($r->errors[0]->code)->toBe('document_too_deep');
    // `true` accepts anything -- but not a document past the bound. The bound
    // is applied before the schema is consulted at all.
    ok(true, json_decode(nested(60)));
});

test('a document past maxDocumentDepth as JSON is refused by the decoder, not after it', function () {
    $r = FancySchema::compile(true)->validateJson(nested(10_000));
    expect($r->valid)->toBeFalse();
    expect($r->errors[0]->code)->toBe('document_too_deep');
});

test('a document past maxDocumentNodes is invalid', function () {
    $r = FancySchema::compile(true, new Limits(maxDocumentNodes: 1000))->validate(range(1, 5000));
    expect($r->valid)->toBeFalse();
    expect($r->errors[0]->code)->toBe('document_too_large');
});

test('document JSON past maxDocumentBytes is invalid before it is decoded', function () {
    $r = FancySchema::compile(true, new Limits(maxDocumentBytes: 16))->validateJson('"'.str_repeat('a', 64).'"');
    expect($r->valid)->toBeFalse();
    expect($r->errors[0]->code)->toBe('document_too_large');
});

test('a document that is not JSON is invalid, not an exception', function () {
    $s = FancySchema::compile(true);
    expect($s->validateJson('{"a":')->errors[0]->code)->toBe('invalid_json');
    expect($s->validateJson('NaN')->errors[0]->code)->toBe('invalid_json');
    expect($s->validate(NAN)->errors[0]->code)->toBe('not_json_value');
    expect($s->validate(['a' => INF])->errors[0]->instancePath)->toBe('/a');
    expect($s->validate(new DateTimeImmutable)->errors[0]->code)->toBe('not_json_value');
    expect($s->validate("\xC3\x28")->errors[0]->code)->toBe('not_json_value');
});

test('a key is a string even when PHP would make it an integer', function () {
    // json_decode('{"0":1}') stores property 0; PHP arrays cast "0" to int.
    $schema = j('{"type":"object","properties":{"0":{"type":"string"}},"required":["0"],"additionalProperties":false}');
    ok($schema, j('{"0":"a"}'));
    expect(bad($schema, j('{"0":1}'))[0]->instancePath)->toBe('/0');
    expect(bad($schema, j('{"1":"a","0":"a"}'))[0]->detail)->toBe('1');
});

test('keys that mean something elsewhere mean nothing here', function () {
    $schema = j('{"type":"object","additionalProperties":false,"properties":{"a":{}}}');
    foreach (['__proto__', 'constructor', '', '$ref', '#', '/', '~'] as $key) {
        $doc = new stdClass;
        $doc->{$key} = 1;
        expect(FancySchema::validate($schema, $doc)->valid)->toBeFalse("key ".json_encode($key)." slipped through");
    }
    ok(j('{"required":[""],"properties":{"":{"type":"integer"}}}'), j('{"":1}'));
});

test('maxErrors caps the list, and the result says it was cut', function () {
    $r = FancySchema::compile(j('{"items":{"type":"string"}}'), new Limits(maxErrors: 5))->validate(range(1, 50));
    expect($r->valid)->toBeFalse();
    expect($r->errors)->toHaveCount(5);
    expect($r->truncated)->toBeTrue();
    $full = FancySchema::validate(j('{"items":{"type":"string"}}'), range(1, 50));
    expect($full->errors)->toHaveCount(50);
    expect($full->truncated)->toBeFalse();
});

test('a huge string is measured, not exploded', function () {
    $s = str_repeat("\u{1F642}", 200_000);
    ok(j('{"minLength":200000}'), $s);
    bad(j('{"minLength":200001}'), $s);
});

test('numbers at the edges', function () {
    ok(j('{"type":"integer"}'), PHP_INT_MAX);
    ok(j('{"type":"integer","maximum":1e308}'), j('1e300'));
    bad(j('{"maximum":9007199254740992}'), j('9007199254740993.5e10'));
    ok(j('{"minimum":-0.0}'), 0);
    ok(j('{"const":0}'), -0.0);
});

// --- limits are settings ---------------------------------------------------

test('every limit has a secure default and can be changed', function () {
    expect((new Limits)->toArray())->toBe([
        'maxSchemaBytes' => 4_194_304,
        'maxSchemaDepth' => 64,
        'maxSchemaNodes' => 50_000,
        'maxDocumentBytes' => 8_388_608,
        'maxDocumentDepth' => 64,
        'maxDocumentNodes' => 100_000,
        'maxEvaluationDepth' => 256,
        'maxSteps' => 1_000_000,
        'maxErrors' => 100,
    ]);
    // Raising the document depth admits what the default refused.
    expect(FancySchema::compile(true, new Limits(maxDocumentDepth: 200))->validate(json_decode(nested(100), false, 512))->valid)->toBeTrue();
    expect(FancySchema::compile(j(str_repeat('{"not":', 100).'{}'.str_repeat('}', 100)), new Limits(maxSchemaDepth: 200)))->toBeObject();
});

test('a limit that is not a positive integer is refused where it is set', function () {
    expect(fn () => new Limits(maxSteps: 0))->toThrow(InvalidArgumentException::class, 'maxSteps');
    expect(fn () => new Limits(maxDocumentDepth: -1))->toThrow(InvalidArgumentException::class, 'maxDocumentDepth');
    expect(fn () => Limits::fromArray(['maxErrors' => '5']))->toThrow(InvalidArgumentException::class, 'maxErrors');
    expect(fn () => Limits::fromArray(['maxStep' => 5]))->toThrow(InvalidArgumentException::class, 'maxStep');
    expect(Limits::fromArray(['maxErrors' => 5])->maxErrors)->toBe(5);
});

test('a deep evaluation through refs stops at maxEvaluationDepth, invalid', function () {
    // Each level enters `properties` once and two refs, so evaluation depth
    // grows three times faster than the document.
    $schema = j('{"$defs":{"a":{"$ref":"#/$defs/b"},"b":{"properties":{"c":{"$ref":"#/$defs/a"}}}},"$ref":"#/$defs/a"}');
    $doc = new stdClass;
    $cur = $doc;
    for ($i = 0; $i < 50; $i++) {
        $cur->c = new stdClass;
        $cur = $cur->c;
    }
    $r = FancySchema::compile($schema, new Limits(maxEvaluationDepth: 40))->validate($doc);
    expect($r->valid)->toBeFalse();
    expect($r->errors[array_key_last($r->errors)]->code)->toBe('max_depth_exceeded');
    expect(FancySchema::validate($schema, $doc)->valid)->toBeTrue();
});
