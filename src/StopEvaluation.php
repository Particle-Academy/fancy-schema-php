<?php

declare(strict_types=1);

namespace ParticleAcademy\FancySchema;

use RuntimeException;

/** @internal A limit was reached, or maxErrors was; the document is invalid. */
final class StopEvaluation extends RuntimeException
{
    public function __construct(public readonly ?ValidationError $error)
    {
        parent::__construct('evaluation stopped');
    }
}
