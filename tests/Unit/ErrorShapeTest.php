<?php

declare(strict_types=1);

use ParticleAcademy\FancySchema\FancySchema;
use ParticleAcademy\FancySchema\ValidationError;

test('every error code is in a closed, stable set', function () {
    expect(ValidationError::CODES)->toBe([
        'additional_property',
        'const_mismatch',
        'document_too_deep',
        'document_too_large',
        'enum_mismatch',
        'evaluation_budget_exceeded',
        'false_schema',
        'invalid_json',
        'max_depth_exceeded',
        'not_json_value',
        'not_matched',
        'number_above_maximum',
        'number_below_minimum',
        'one_of_many',
        'one_of_none',
        'required_missing',
        'string_too_short',
        'type_mismatch',
    ]);
});

test('an error serialises to the JS ValidationError shape plus its code', function () {
    $e = FancySchema::validate(j('{"properties":{"a":{"type":"string"}}}'), j('{"a":1}'))->errors[0];
    expect($e->toArray())->toBe([
        'code' => 'type_mismatch',
        'keyword' => 'type',
        'instancePath' => '/a',
        'schemaPath' => '#/properties/a/type',
        'message' => 'expected string, got integer',
        'detail' => 'integer',
    ]);
});

test('an error with no detail omits the key, as the JS one does', function () {
    $e = FancySchema::validate(j('{"enum":[1]}'), 2)->errors[0];
    expect($e->toArray())->not->toHaveKey('detail');
});

test('a whole-document failure points at the root with the empty pointer', function () {
    $e = FancySchema::validate(j('{"type":"string"}'), 1)->errors[0];
    expect($e->instancePath)->toBe('');
    expect($e->schemaPath)->toBe('#/type');
});

test('a result serialises with its verdict', function () {
    $r = FancySchema::validate(true, 1);
    expect($r->toArray())->toBe(['valid' => true, 'errors' => [], 'truncated' => false]);
});

test('the message never echoes the document value', function () {
    // A value can be a secret or megabytes long; the pointer locates it.
    $secret = 'sk-live-'.str_repeat('x', 40);
    foreach ([
        ['{"type":"integer"}', $secret],
        ['{"enum":["a"]}', $secret],
        ['{"const":"a"}', $secret],
        ['{"minLength":100}', $secret],
        ['{"not":{"type":"string"}}', $secret],
        ['{"additionalProperties":false}', (object) [$secret => 1]],
    ] as [$schema, $doc]) {
        $errors = FancySchema::validate(j($schema), $doc)->errors;
        expect($errors)->not->toBe([], "{$schema} should have rejected the document");
        foreach ($errors as $e) {
            expect($e->message)->not->toContain('sk-live-');
        }
    }
});
