<?php

declare(strict_types=1);

use ParticleAcademy\FancySchema\FancySchema;

/*
 * The in-scope cases of @particle-academy/fancy-schema's `test/validate.test.ts`,
 * ported one for one (the JS test name is in each description). Cases whose
 * keywords 0.1 does not implement (anyOf, if/then/else, maxLength, pattern,
 * exclusiveMaximum, multipleOf, minItems, uniqueItems) are NOT dropped silently:
 * FailClosedTest asserts that each of them is refused.
 *
 * The shared DATA row set is fancy-schema's prism corpus, read from a checkout
 * by tests/Conformance. These ported cases are code, not data: the JS suite
 * keeps them inline, so there is nothing to read in place.
 */

test('[js: type] accepts the declared type and rejects the others', function () {
    ok(j('{"type":"string"}'), 'x');
    ok(j('{"type":"integer"}'), 3);
    ok(j('{"type":"number"}'), 3.5);
    ok(j('{"type":"boolean"}'), false);
    ok(j('{"type":"null"}'), null);
    ok(j('{"type":"array"}'), []);
    ok(j('{"type":"object"}'), j('{}'));

    bad(j('{"type":"string"}'), 3);
    bad(j('{"type":"integer"}'), 3.5);
    // null is its own type, never an object.
    bad(j('{"type":"object"}'), null);
    // A list is not an object, and an object is not a list.
    bad(j('{"type":"object"}'), []);
    bad(j('{"type":"array"}'), j('{}'));
});

test('an integral float is an integer, and every integer is a number', function () {
    // JSON has one number type. 1.0 and 1 are the same value (the JS validator
    // sees no difference at all), so a PHP float that holds an integer must
    // satisfy `integer`, or the two validators disagree on the same document.
    ok(j('{"type":"integer"}'), 1.0);
    ok(j('{"type":"integer"}'), j('1.0'));
    ok(j('{"type":"number"}'), 7);
    bad(j('{"type":"integer"}'), 1.5);
    bad(j('{"type":"number"}'), '7');
    bad(j('{"type":"boolean"}'), 0);
});

test('[js: type accepts a union]', function () {
    ok(j('{"type":["string","null"]}'), null);
    ok(j('{"type":["string","null"]}'), 'x');
    bad(j('{"type":["string","null"]}'), 1);
});

test('[js: required] names every missing property, not just the first', function () {
    $errors = bad(j('{"type":"object","required":["a","b","c"]}'), j('{"a":1}'));
    $missing = array_values(array_map(fn ($e) => $e->detail, array_filter($errors, fn ($e) => $e->keyword === 'required')));
    expect($missing)->toBe(['b', 'c']);
    expect($errors[0]->code)->toBe('required_missing');
});

test('[js: additionalProperties: false] rejects an unknown property AND names it', function () {
    $schema = j('{"type":"object","properties":{"id":{"type":"string"}},"additionalProperties":false}');
    ok($schema, j('{"id":"a"}'));
    $errors = bad($schema, j('{"id":"a","nope":1}'));
    expect($errors)->toHaveCount(1);
    expect($errors[0]->keyword)->toBe('additionalProperties');
    expect($errors[0]->instancePath)->toBe('/nope');
    expect($errors[0]->detail)->toBe('nope');
    expect($errors[0]->code)->toBe('additional_property');
});

test('additionalProperties as a schema validates every extra property against it', function () {
    $schema = j('{"type":"object","properties":{"id":{"type":"string"}},"additionalProperties":{"type":"integer"}}');
    ok($schema, j('{"id":"a","n":1,"m":2}'));
    $errors = bad($schema, j('{"id":"a","n":"one"}'));
    expect($errors[0]->instancePath)->toBe('/n');
    expect($errors[0]->schemaPath)->toBe('#/additionalProperties/type');
});

test('[js: properties are only checked when present]', function () {
    $schema = j('{"type":"object","properties":{"n":{"type":"number"}}}');
    ok($schema, j('{}'));
    bad($schema, j('{"n":"no"}'));
});

test('object keywords do not apply to a non-object, and string keywords not to a non-string', function () {
    ok(j('{"required":["a"],"properties":{"a":false},"additionalProperties":false}'), 'not an object');
    ok(j('{"minLength":5}'), 3);
    ok(j('{"minimum":5}'), 'abc');
    ok(j('{"items":false}'), j('{}'));
});

test('[js: $ref resolves against $defs, including a sibling pointer]', function () {
    $schema = j('{
        "type":"object",
        "properties":{"who":{"$ref":"#/$defs/person"}},
        "$defs":{"person":{"type":"object","properties":{"name":{"type":"string"}},"required":["name"],"additionalProperties":false}}
    }');
    ok($schema, j('{"who":{"name":"ada"}}'));
    $errors = bad($schema, j('{"who":{"name":1}}'));
    expect($errors[0]->instancePath)->toBe('/who/name');
    expect($errors[0]->schemaPath)->toBe('#/$defs/person/properties/name/type');
});

test('$ref sits ALONGSIDE its siblings in 2020-12; it does not replace them', function () {
    $schema = j('{"$defs":{"s":{"type":"string"}},"$ref":"#/$defs/s","minLength":3}');
    ok($schema, 'abc');
    expect(bad($schema, 'ab')[0]->keyword)->toBe('minLength');
    expect(bad($schema, 5)[0]->keyword)->toBe('type');
});

test('[js: $ref handles recursion without blowing the stack]', function () {
    $schema = j('{
        "$defs":{"node":{"type":"object","properties":{"children":{"type":"array","items":{"$ref":"#/$defs/node"}}},"additionalProperties":false}},
        "$ref":"#/$defs/node"
    }');
    ok($schema, j('{"children":[{"children":[]},{"children":[{"children":[]}]}]}'));
    bad($schema, j('{"children":[{"nope":1}]}'));
});

test('[js: oneOf] exactly one, and the error says how many matched', function () {
    $schema = j('{"oneOf":[
        {"type":"object","properties":{"kind":{"const":"a"}},"required":["kind"]},
        {"type":"object","properties":{"kind":{"const":"b"}},"required":["kind"]}
    ]}');
    ok($schema, j('{"kind":"a"}'));
    ok($schema, j('{"kind":"b"}'));
    $none = bad($schema, j('{"kind":"c"}'));
    expect($none[0]->keyword)->toBe('oneOf');
    expect($none[0]->code)->toBe('one_of_none');
    expect($none[0]->detail)->toBe('0');

    // TWO matching is also a failure, and it is the one people forget.
    $many = bad(j('{"oneOf":[{"type":"object"},{"type":"object"}]}'), j('{}'));
    expect($many[0]->keyword)->toBe('oneOf');
    expect($many[0]->code)->toBe('one_of_many');
    expect($many[0]->detail)->toBe('2');
});

test('[js: allOf]', function () {
    ok(j('{"allOf":[{"type":"number"},{"minimum":2}]}'), 3);
    $errors = bad(j('{"allOf":[{"type":"number"},{"minimum":2}]}'), 1);
    expect($errors[0]->schemaPath)->toBe('#/allOf/1/minimum');
});

test('not: rejects a value matching the subschema', function () {
    ok(j('{"not":{"type":"string"}}'), 1);
    $errors = bad(j('{"not":{"type":"string"}}'), 'x');
    expect($errors[0]->keyword)->toBe('not');
    expect($errors[0]->code)->toBe('not_matched');
});

test('[js: enum and const] with deep equality, not identity', function () {
    ok(j('{"enum":["a","b"]}'), 'b');
    expect(bad(j('{"enum":["a","b"]}'), 'c')[0]->code)->toBe('enum_mismatch');
    ok(j('{"const":7}'), 7);
    expect(bad(j('{"const":7}'), '7')[0]->code)->toBe('const_mismatch');
    ok(j('{"const":{"a":[1,2]}}'), j('{"a":[1,2]}'));
    bad(j('{"const":{"a":[1,2]}}'), j('{"a":[2,1]}'));
    // Key ORDER is not significant; 1 and 1.0 are the same JSON number.
    ok(j('{"const":{"a":1,"b":2}}'), j('{"b":2,"a":1}'));
    ok(j('{"const":1}'), 1.0);
    // But true is not 1, "1" is not 1, and null is not false or 0.
    bad(j('{"const":1}'), true);
    bad(j('{"enum":[1]}'), '1');
    bad(j('{"enum":[false,0]}'), null);
    // An extra or a missing key is a different object.
    bad(j('{"const":{"a":1}}'), j('{"a":1,"b":2}'));
    bad(j('{"const":{"a":1,"b":2}}'), j('{"a":1}'));
    bad(j('{"const":{"a":1}}'), j('{"b":1}'));
    bad(j('{"const":[1,2]}'), [1, 2, 3]);
    bad(j('{"const":{}}'), []);
    bad(j('{"const":[]}'), j('{}'));
});

test('[js: string and number constraints] in scope: minLength, minimum, maximum', function () {
    ok(j('{"type":"string","minLength":2}'), 'ab');
    expect(bad(j('{"type":"string","minLength":2}'), 'a')[0]->code)->toBe('string_too_short');
    ok(j('{"type":"number","minimum":1,"maximum":10}'), 10);
    ok(j('{"type":"number","minimum":1,"maximum":10}'), 1);
    expect(bad(j('{"type":"number","minimum":1}'), 0.5)[0]->code)->toBe('number_below_minimum');
    expect(bad(j('{"type":"number","maximum":10}'), 10.5)[0]->code)->toBe('number_above_maximum');
});

test('minLength counts code points, not bytes or UTF-16 units', function () {
    // One emoji is one character. strlen() says 4; JS .length says 2.
    ok(j('{"minLength":1}'), "\u{1F642}");
    bad(j('{"minLength":2}'), "\u{1F642}");
    ok(j('{"minLength":3}'), 'héé');
});

test('[js: items] validates every element and points at the failing one', function () {
    ok(j('{"type":"array","items":{"type":"string"}}'), ['a']);
    $errors = bad(j('{"type":"array","items":{"type":"string"}}'), ['a', 1]);
    expect($errors[0]->instancePath)->toBe('/1');
});

test('[js: booleans are schemas] true accepts anything, false rejects everything', function () {
    ok(true, j('{"whatever":1}'));
    $errors = bad(false, 1);
    expect($errors[0]->keyword)->toBe('false');
    expect($errors[0]->code)->toBe('false_schema');
    $schema = j('{"type":"object","properties":{"banned":false}}');
    ok($schema, j('{"other":1}'));
    bad($schema, j('{"banned":1}'));
});

test('[js: errors carry a JSON Pointer path and the schema path that rejected]', function () {
    $schema = j('{"type":"object","properties":{"rows":{"type":"array","items":{"type":"object","properties":{"n":{"type":"integer"}}}}}}');
    $e = bad($schema, j('{"rows":[{"n":1},{"n":"two"}]}'))[0];
    expect($e->instancePath)->toBe('/rows/1/n');
    expect($e->schemaPath)->toBe('#/properties/rows/items/properties/n/type');
    expect($e->message)->toBeString()->not->toBe('');
    expect($e->detail)->toBe('string');
});

test('[js: a JSON Pointer path escapes / and ~ per RFC 6901]', function () {
    $errors = bad(j('{"type":"object","properties":{"a/b":{"type":"string"},"c~d":{"type":"string"}}}'), j('{"a/b":1,"c~d":2}'));
    expect(array_map(fn ($e) => $e->instancePath, $errors))->toBe(['/a~1b', '/c~0d']);
    expect($errors[0]->schemaPath)->toBe('#/properties/a~1b/type');
});

test('[js: compile() validates many documents against one schema]', function () {
    $check = FancySchema::compile(j('{"type":"object","required":["id"],"additionalProperties":false,"properties":{"id":{"type":"string"}}}'));
    $docs = [j('{"id":"a"}'), j('{"id":1}'), j('{"id":"c","extra":true}'), j('{}')];
    expect(array_map(fn ($d) => $check->validate($d)->valid, $docs))->toBe([true, false, false, false]);
});

test('[js: unknown keywords] ANNOTATIONS are accepted; they assert nothing', function () {
    // The JS validator ignores every unknown keyword. This one ignores only the
    // ones that are annotations by definition and refuses the rest (see
    // FailClosedTest). Both agree on this schema.
    ok(j('{
        "$schema":"https://json-schema.org/draft/2020-12/schema",
        "$id":"https://example.test/x.json",
        "$comment":"a note",
        "title":"A thing",
        "description":"...",
        "examples":[1],
        "default":1,
        "deprecated":true,
        "readOnly":false,
        "writeOnly":false,
        "type":"number"
    }'), 1);
});

test('an associative PHP array is an object and a list is an array', function () {
    // For callers who decoded with json_decode(..., true).
    ok(['type' => 'object', 'required' => ['a'], 'properties' => ['a' => ['type' => 'integer']]], ['a' => 1]);
    bad(['type' => 'object', 'required' => ['a']], ['b' => 1]);
    bad(['type' => 'array'], ['a' => 1]);
    // An empty map in a SCHEMA position is unambiguous: it is the empty schema.
    ok(['type' => 'object', 'properties' => [], 'additionalProperties' => []], ['x' => 1]);
});

test('validateJson decodes objects as objects, so {} is not []', function () {
    $s = FancySchema::compileJson('{"type":"object"}');
    expect($s->validateJson('{}')->valid)->toBeTrue();
    expect($s->validateJson('[]')->valid)->toBeFalse();
});
