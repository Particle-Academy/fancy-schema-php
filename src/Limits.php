<?php

declare(strict_types=1);

namespace ParticleAcademy\FancySchema;

use InvalidArgumentException;

/**
 * Every bound the validator applies. Each has a secure default and each can be
 * changed; a breach always fails CLOSED (a schema is refused, a document is
 * invalid), never open.
 */
final readonly class Limits
{
    public function __construct(
        /** Bytes of schema JSON accepted by compileJson(), checked before decoding. */
        public int $maxSchemaBytes = 4_194_304,
        /** Nesting of arrays and objects in a schema. */
        public int $maxSchemaDepth = 64,
        /** JSON values in a schema, including every const and enum value. */
        public int $maxSchemaNodes = 50_000,
        /** Bytes of document JSON accepted by validateJson(), checked before decoding. */
        public int $maxDocumentBytes = 8_388_608,
        /** Nesting of arrays and objects in a document. */
        public int $maxDocumentDepth = 64,
        /** JSON values in a document. */
        public int $maxDocumentNodes = 100_000,
        /** Recursion while evaluating: every subschema entered, through $ref and the combinators. */
        public int $maxEvaluationDepth = 256,
        /** Subschema evaluations per document, oneOf and not probes included. */
        public int $maxSteps = 1_000_000,
        /** Errors kept per document; the result says when it was cut. */
        public int $maxErrors = 100,
    ) {
        foreach ($this->toArray() as $name => $value) {
            if ($value < 1) {
                throw new InvalidArgumentException("Limit {$name} must be a positive integer, got {$value}.");
            }
        }
    }

    /** @param array<string, mixed> $limits */
    public static function fromArray(array $limits): self
    {
        $known = array_keys((new self)->toArray());
        foreach ($limits as $name => $value) {
            if (! in_array($name, $known, true)) {
                throw new InvalidArgumentException("Unknown limit {$name}. Known: ".implode(', ', $known).'.');
            }
            if (! is_int($value)) {
                throw new InvalidArgumentException("Limit {$name} must be a positive integer, got ".get_debug_type($value).'.');
            }
        }

        return new self(...$limits);
    }

    /** @return array<string, int> */
    public function toArray(): array
    {
        return [
            'maxSchemaBytes' => $this->maxSchemaBytes,
            'maxSchemaDepth' => $this->maxSchemaDepth,
            'maxSchemaNodes' => $this->maxSchemaNodes,
            'maxDocumentBytes' => $this->maxDocumentBytes,
            'maxDocumentDepth' => $this->maxDocumentDepth,
            'maxDocumentNodes' => $this->maxDocumentNodes,
            'maxEvaluationDepth' => $this->maxEvaluationDepth,
            'maxSteps' => $this->maxSteps,
            'maxErrors' => $this->maxErrors,
        ];
    }
}
