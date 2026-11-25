<?php

declare(strict_types=1);

namespace ParticleAcademy\FancySchema;

use Exception;

/** @internal */
final class NormalizeFailure extends Exception
{
    public function __construct(public readonly string $kind, public readonly string $pointer, public readonly string $detail)
    {
        parent::__construct($detail);
    }
}
