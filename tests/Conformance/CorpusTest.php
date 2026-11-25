<?php

declare(strict_types=1);

use ParticleAcademy\FancySchema\FancySchema;
use ParticleAcademy\FancySchema\Limits;
use ParticleAcademy\FancySchema\SchemaException;
use ParticleAcademy\FancySchema\Tests\Support\Corpus;

/*
 * fancy-schema's conformance corpus: prism-parity's `cases.schema.json` plus 31
 * documents, with verdicts from Python jsonschema 4.26.0 -- NOT from either of
 * our validators. Read from fancy-schema's own checkout (Support/Corpus.php).
 *
 * ## What 0.1 can and cannot assert against it
 *
 * The corpus schema needs two things outside the approved 0.1 scope: nine
 * keywords, and six $refs that point INSIDE a definition
 * ("#/$defs/securityCase/properties/id") where 0.1 resolves only
 * "#/$defs/<name>". A fail-closed validator therefore REFUSES it, and that
 * refusal is the one correct answer 0.1 can give: ignoring either would hand
 * back verdicts for a schema it never fully evaluated. So this file asserts:
 *
 *   1. the fixture is the shape we believe (a vacuity guard);
 *   2. compiling the corpus schema throws, naming EXACTLY those keywords and
 *      those refs -- so the day the scope grows, this test fails and says so,
 *      and the verdict test below starts comparing verdicts;
 *   3. every document verdict, the moment the schema compiles.
 *
 * (3) is written now, so it is not forgotten later: today it can only prove
 * that it cannot run yet.
 */

/** The keywords the corpus needs that 0.1 does not implement. Sorted. */
const CORPUS_UNSUPPORTED_KEYWORDS = [
    'exclusiveMinimum',
    'if',
    'maxItems',
    'minItems',
    'minProperties',
    'pattern',
    'patternProperties',
    'propertyNames',
    'then',
];

/** The refs the corpus uses that point inside a definition. Sorted. */
const CORPUS_UNSUPPORTED_REFS = [
    '#/$defs/securityCase/properties/agrees',
    '#/$defs/securityCase/properties/id',
    '#/$defs/securityCase/properties/notes',
    '#/$defs/securityCase/properties/since',
    '#/$defs/securityCase/properties/skip',
    '#/$defs/securityCase/properties/title',
];

/** Read the value at a "#/a/b" schema pointer. */
function corpusAt(object $schema, string $pointer): mixed
{
    $node = $schema;
    foreach (explode('/', substr($pointer, 2)) as $token) {
        $key = str_replace(['~1', '~0'], ['/', '~'], $token);
        $node = is_array($node) ? $node[(int) $key] : $node->{$key};
    }

    return $node;
}

test('the fixture is the shape this test believes it is', function () {
    $c = Corpus::load();
    expect($c->positive_documents)->not->toBe([], 'no positive documents; this suite would assert nothing');
    expect($c->negative_documents)->not->toBe([], 'no negative documents; this suite would assert nothing');
    expect($c->source->verdicts_from)->toContain('jsonschema');
    $expect = array_unique(array_map(fn ($d) => $d->expect_valid, $c->negative_documents));
    expect($expect)->toContain(true)->toContain(false);
});

test('0.1 refuses the corpus schema, naming exactly the keywords and refs it lacks', function () {
    $c = Corpus::load();
    try {
        FancySchema::compile($c->schema);
        test()->fail('the corpus schema compiled: the scope moved, so the verdict test below now compares verdicts');
    } catch (SchemaException $e) {
        $keywords = [];
        $refs = [];
        foreach ($e->problems as $p) {
            if ($p['reason'] === 'unsupported_ref') {
                $refs[corpusAt($c->schema, $p['pointer'])] = true;

                continue;
            }
            expect($p['reason'])->toBe('unsupported_keyword', 'an unexpected refusal: '.$p['reason'].' at '.$p['pointer']);
            $keywords[substr($p['pointer'], strrpos($p['pointer'], '/') + 1)] = true;
        }
        $keywords = array_keys($keywords);
        $refs = array_keys($refs);
        sort($keywords);
        sort($refs);
        expect($keywords)->toBe(CORPUS_UNSUPPORTED_KEYWORDS);
        expect($refs)->toBe(CORPUS_UNSUPPORTED_REFS);
    }
});

test('every corpus document gets the verdict the standard gives it', function () {
    $c = Corpus::load();
    try {
        $validator = FancySchema::compile($c->schema, new Limits(maxSchemaNodes: 200_000));
    } catch (SchemaException) {
        // Refused, as the test above requires. Nothing to compare yet.
        expect(true)->toBeTrue();

        return;
    }

    $disagreements = [];
    foreach ($c->positive_documents as $d) {
        if ($validator->validate($d->document)->valid !== $d->expect_valid) {
            $disagreements[] = "positive \"{$d->suite}\"";
        }
    }
    foreach ($c->negative_documents as $d) {
        if ($validator->validate($d->document)->valid !== $d->expect_valid) {
            $disagreements[] = "negative \"{$d->label}\"";
        }
    }
    expect($disagreements)->toBe([]);
});
