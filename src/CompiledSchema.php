<?php

declare(strict_types=1);

namespace ParticleAcademy\FancySchema;

/**
 * A schema that has been checked in full. Validate as many documents against
 * it as you like; it holds no per-document state.
 */
final class CompiledSchema
{
    /** @internal Use {@see FancySchema::compile()}. */
    public function __construct(private readonly bool|JsonObject $root, public readonly Limits $limits) {}

    /** Validate a decoded document (stdClass objects, or associative arrays). */
    public function validate(mixed $document): Result
    {
        try {
            $doc = Json::normalize($document, $this->limits->maxDocumentDepth, $this->limits->maxDocumentNodes);
        } catch (NormalizeFailure $f) {
            return new Result(false, [match ($f->kind) {
                Json::FAIL_DEPTH => new ValidationError('document_too_deep', 'maxDocumentDepth', $f->pointer, '#', "document nests deeper than maxDocumentDepth {$this->limits->maxDocumentDepth}"),
                Json::FAIL_SIZE => new ValidationError('document_too_large', 'maxDocumentNodes', $f->pointer, '#', "document holds more than maxDocumentNodes {$this->limits->maxDocumentNodes} values"),
                default => new ValidationError('not_json_value', 'json', $f->pointer, '#', $f->detail),
            }]);
        }

        return (new Evaluation($this->limits))->run($this->root, $doc);
    }

    /** Validate a document from JSON text. Text over Limits::$maxDocumentBytes is invalid before it is decoded. */
    public function validateJson(string $json): Result
    {
        $decoded = Json::decodeDocument($json, $this->limits);
        if ($decoded instanceof ValidationError) {
            return new Result(false, [$decoded]);
        }

        return $this->validate($decoded);
    }
}
