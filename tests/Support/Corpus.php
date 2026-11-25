<?php

declare(strict_types=1);

namespace ParticleAcademy\FancySchema\Tests\Support;

use RuntimeException;

/**
 * fancy-schema's shared corpus, read IN PLACE from a fancy-schema checkout.
 *
 * Never copied into this repo: a copy is a fork, and a fork drifts silently.
 * CI checks fancy-schema out at the TAG below (ci.yml), and PinTest fails if
 * the two disagree. Locally the envelope's sibling checkout is used.
 */
final class Corpus
{
    /** The fancy-schema tag CI reads the corpus from. Move with ci.yml. */
    public const PINNED_REF = 'v0.2.1';

    public const RELATIVE_PATH = 'test/fixtures/prism-corpus.json';

    public static function root(): string
    {
        $env = getenv('FANCY_SCHEMA_ROOT');
        $root = is_string($env) && $env !== '' ? $env : dirname(__DIR__, 3).'/fancy-schema';

        if (! is_file($root.'/'.self::RELATIVE_PATH)) {
            // Fail, never skip. A skipped conformance suite is a green tick
            // over zero assertions.
            throw new RuntimeException("fancy-schema corpus not found under {$root}. Set FANCY_SCHEMA_ROOT to a fancy-schema checkout at ".self::PINNED_REF.'.');
        }

        return $root;
    }

    /** @return object{README: string, source: object, schema: object, positive_documents: list<object>, negative_documents: list<object>} */
    public static function load(): object
    {
        return json_decode((string) file_get_contents(self::root().'/'.self::RELATIVE_PATH), false, 512, JSON_THROW_ON_ERROR);
    }
}
