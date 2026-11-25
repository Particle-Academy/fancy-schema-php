<?php

declare(strict_types=1);

namespace ParticleAcademy\FancySchema;

use RuntimeException;

/**
 * The schema was REFUSED: this validator cannot evaluate it in full, so it
 * judges no document with it.
 *
 * `reason` and `pointer` describe the first problem; `problems` lists every one
 * found, so a schema author fixes them in one pass rather than one per run.
 */
final class SchemaException extends RuntimeException
{
    /** Every reason a schema can be refused for. Closed: adding one is a minor release. */
    public const REASONS = [
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
    ];

    /** Stable reason for the first problem, one of {@see self::REASONS}. */
    public readonly string $reason;

    /** Schema pointer of the first problem, e.g. "#/properties/a/format". */
    public readonly string $pointer;

    /**
     * @param  non-empty-list<array{reason: string, pointer: string, message: string}>  $problems
     */
    public function __construct(public readonly array $problems)
    {
        $first = $problems[0];
        $this->reason = $first['reason'];
        $this->pointer = $first['pointer'];
        $more = count($problems) - 1;
        parent::__construct(
            "Schema refused: {$first['message']} at {$first['pointer']} ({$first['reason']})"
            .($more > 0 ? " and {$more} more problem".($more === 1 ? '' : 's') : '').'.'
        );
    }

    public static function one(string $reason, string $pointer, string $message): self
    {
        return new self([['reason' => $reason, 'pointer' => $pointer, 'message' => $message]]);
    }
}
