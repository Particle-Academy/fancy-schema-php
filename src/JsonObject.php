<?php

declare(strict_types=1);

namespace ParticleAcademy\FancySchema;

/**
 * A JSON object after normalisation. Lists stay PHP lists, so an object is
 * never confused with an array, whichever way the caller decoded.
 *
 * Keys are strings in JSON. PHP stores "0" as the integer 0 in an array, so
 * every reader casts a key back with (string) before using it.
 *
 * @internal
 */
final class JsonObject
{
    /** Set by the compiler on a schema object that carries a resolved $ref. */
    public bool $hasRef = false;

    /** The $ref target: bool or JsonObject. */
    public mixed $refTarget = null;

    /** The $ref as written, used as the schema path after following it. */
    public string $refPath = '';

    /** @param array<array-key, mixed> $props */
    public function __construct(public array $props = []) {}

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->props);
    }
}
