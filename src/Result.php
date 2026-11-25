<?php

declare(strict_types=1);

namespace ParticleAcademy\FancySchema;

final readonly class Result
{
    /**
     * @param  list<ValidationError>  $errors
     */
    public function __construct(
        public bool $valid,
        public array $errors,
        /** True when Limits::$maxErrors cut the list; the document is invalid either way. */
        public bool $truncated = false,
    ) {}

    /** @return array{valid: bool, errors: list<array<string, string>>, truncated: bool} */
    public function toArray(): array
    {
        return [
            'valid' => $this->valid,
            'errors' => array_map(fn (ValidationError $e) => $e->toArray(), $this->errors),
            'truncated' => $this->truncated,
        ];
    }
}
