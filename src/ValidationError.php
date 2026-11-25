<?php

declare(strict_types=1);

namespace ParticleAcademy\FancySchema;

/**
 * One reason a document is invalid.
 *
 * The shape is @particle-academy/fancy-schema's ValidationError (instancePath,
 * schemaPath, keyword, message, detail) plus `code`, a stable machine-readable
 * reason from {@see self::CODES}. Match on `code`; `message` is for people and
 * may be reworded. A message never repeats a document value: the pointer
 * locates it, and the value may be a secret or megabytes long.
 */
final readonly class ValidationError
{
    /** Every code an error can carry. Closed: adding one is a minor release. */
    public const CODES = [
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
    ];

    public function __construct(
        /** Stable reason, one of {@see self::CODES}. */
        public string $code,
        /** The keyword that rejected, as the JS validator names it ("false" for a false schema). */
        public string $keyword,
        /** RFC 6901 pointer to the failing value; "" is the whole document. */
        public string $instancePath,
        /** Pointer to the rejecting keyword, e.g. "#/properties/n/type". */
        public string $schemaPath,
        /** One line, for people. */
        public string $message,
        /** The missing key, the unexpected property, the count, the type seen. */
        public ?string $detail = null,
    ) {}

    /** @return array{code: string, keyword: string, instancePath: string, schemaPath: string, message: string, detail?: string} */
    public function toArray(): array
    {
        $out = [
            'code' => $this->code,
            'keyword' => $this->keyword,
            'instancePath' => $this->instancePath,
            'schemaPath' => $this->schemaPath,
            'message' => $this->message,
        ];
        if ($this->detail !== null) {
            $out['detail'] = $this->detail;
        }

        return $out;
    }
}
