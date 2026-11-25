<?php

declare(strict_types=1);

namespace ParticleAcademy\FancySchema;

use JsonException;
use stdClass;

/**
 * Decoding and normalisation, bounded.
 *
 * Every value is copied into one representation before anything looks at it:
 * stdClass and associative arrays become {@see JsonObject}, lists stay lists,
 * scalars are checked to be JSON (finite numbers, UTF-8 strings). Depth and
 * node count are enforced during the copy, so the bound holds before the
 * schema or the validator ever recurses into the value.
 *
 * @internal
 */
final class Json
{
    public const FAIL_DEPTH = 'depth';

    public const FAIL_SIZE = 'size';

    public const FAIL_VALUE = 'value';

    public static function decodeSchema(string $json, Limits $limits): mixed
    {
        if (strlen($json) > $limits->maxSchemaBytes) {
            throw SchemaException::one('schema_too_large', '#', 'schema JSON is '.strlen($json)." bytes, over maxSchemaBytes {$limits->maxSchemaBytes}");
        }
        try {
            // json_decode's depth counts containers + 1.
            return json_decode($json, false, $limits->maxSchemaDepth + 1, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            if ($e->getCode() === JSON_ERROR_DEPTH) {
                throw SchemaException::one('schema_too_deep', '#', "schema nests deeper than maxSchemaDepth {$limits->maxSchemaDepth}");
            }
            throw SchemaException::one('invalid_json', '#', 'schema is not valid JSON');
        }
    }

    /**
     * Decode a document, or return the ValidationError that makes it invalid.
     */
    public static function decodeDocument(string $json, Limits $limits): mixed
    {
        if (strlen($json) > $limits->maxDocumentBytes) {
            return new ValidationError('document_too_large', 'maxDocumentBytes', '', '#', "document JSON is over maxDocumentBytes {$limits->maxDocumentBytes}", (string) strlen($json));
        }
        try {
            return json_decode($json, false, $limits->maxDocumentDepth + 1, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            if ($e->getCode() === JSON_ERROR_DEPTH) {
                return new ValidationError('document_too_deep', 'maxDocumentDepth', '', '#', "document nests deeper than maxDocumentDepth {$limits->maxDocumentDepth}");
            }

            return new ValidationError('invalid_json', 'json', '', '#', 'document is not valid JSON');
        }
    }

    /**
     * @throws NormalizeFailure
     */
    public static function normalize(mixed $value, int $maxDepth, int $maxNodes): mixed
    {
        $nodes = 0;

        return self::walk($value, '', 0, $maxDepth, $maxNodes, $nodes);
    }

    public static function escape(string $token): string
    {
        return str_replace(['~', '/'], ['~0', '~1'], $token);
    }

    private static function walk(mixed $v, string $ptr, int $depth, int $maxDepth, int $maxNodes, int &$nodes): mixed
    {
        if (++$nodes > $maxNodes) {
            throw new NormalizeFailure(self::FAIL_SIZE, $ptr, "more than {$maxNodes} values");
        }

        if ($v === null || is_bool($v) || is_int($v)) {
            return $v;
        }
        if (is_float($v)) {
            if (! is_finite($v)) {
                throw new NormalizeFailure(self::FAIL_VALUE, $ptr, 'NaN and Infinity are not JSON numbers');
            }

            return $v;
        }
        if (is_string($v)) {
            if (! mb_check_encoding($v, 'UTF-8')) {
                throw new NormalizeFailure(self::FAIL_VALUE, $ptr, 'a string that is not valid UTF-8 is not JSON');
            }

            return $v;
        }

        if ($v instanceof stdClass) {
            $entries = get_object_vars($v);
            $isList = false;
        } elseif (is_array($v)) {
            $entries = $v;
            $isList = array_is_list($v);
        } else {
            throw new NormalizeFailure(self::FAIL_VALUE, $ptr, get_debug_type($v).' is not a JSON value');
        }

        if ($depth + 1 > $maxDepth) {
            throw new NormalizeFailure(self::FAIL_DEPTH, $ptr, "nests deeper than {$maxDepth}");
        }

        $out = [];
        foreach ($entries as $k => $item) {
            $key = (string) $k;
            if (! $isList && ! mb_check_encoding($key, 'UTF-8')) {
                throw new NormalizeFailure(self::FAIL_VALUE, $ptr, 'a key that is not valid UTF-8 is not JSON');
            }
            $out[$key] = self::walk($item, $ptr.'/'.self::escape($key), $depth + 1, $maxDepth, $maxNodes, $nodes);
        }

        return $isList ? array_values($out) : new JsonObject($out);
    }
}
