<?php

declare(strict_types=1);

namespace ParticleAcademy\FancySchema;

/**
 * Checks a whole schema BEFORE any document is judged by it, and refuses it
 * unless every keyword in it is one this validator evaluates.
 *
 * That is the fail-closed half of the package. The JS validator ignores a
 * keyword it does not know; for an assertion that can only make a document MORE
 * valid, and under `not` it inverts. So here an unknown keyword, a malformed
 * keyword value, a $ref outside the root $defs, a $ref that does not resolve,
 * and a $ref cycle that consumes no input are each a {@see SchemaException},
 * collected across the whole schema, including $defs nothing references.
 *
 * Scope is deliberately the 0.1 set approved in CC-fancy-0068: the keywords
 * Prism's ACP schema uses, and $ref only to #/$defs/<name>. The shared
 * fancy-schema corpus needs more (exclusiveMinimum, if, then, maxItems,
 * minItems, minProperties, pattern, patternProperties, propertyNames, and refs
 * that point inside a definition), so this class refuses it, and CorpusTest
 * asserts that refusal. The owner chose to leave it there (q_f21e5d1f,
 * 2026-11-25): widen the scope only when a consumer needs it, through the
 * change council, adding each keyword with its evaluation, value check, ported
 * cases and fail-closed cases together.
 *
 * @internal
 */
final class Compiler
{
    /** The dialect. With or without the empty fragment. */
    private const DIALECTS = [
        'https://json-schema.org/draft/2020-12/schema',
        'https://json-schema.org/draft/2020-12/schema#',
    ];

    /** Annotations assert nothing, by definition, so they are safe to accept anywhere. */
    private const ANNOTATIONS = ['$comment', 'title', 'description', 'default', 'examples', 'deprecated', 'readOnly', 'writeOnly'];

    private const TYPES = ['string', 'number', 'integer', 'boolean', 'object', 'array', 'null'];

    /** @var list<array{reason: string, pointer: string, message: string}> */
    private array $problems = [];

    /** @var list<array{node: JsonObject, name: string, pointer: string}> */
    private array $refs = [];

    /** Every schema object, for the cycle check. @var list<JsonObject> */
    private array $objects = [];

    public static function compile(mixed $schema, Limits $limits): bool|JsonObject
    {
        try {
            $root = Json::normalize($schema, $limits->maxSchemaDepth, $limits->maxSchemaNodes);
        } catch (NormalizeFailure $f) {
            $reason = match ($f->kind) {
                Json::FAIL_DEPTH => 'schema_too_deep',
                Json::FAIL_SIZE => 'schema_too_large',
                default => 'not_json_value',
            };
            $message = match ($f->kind) {
                Json::FAIL_DEPTH => "schema nests deeper than maxSchemaDepth {$limits->maxSchemaDepth}",
                Json::FAIL_SIZE => "schema holds more than maxSchemaNodes {$limits->maxSchemaNodes} values",
                default => $f->detail,
            };
            throw SchemaException::one($reason, '#'.$f->pointer, $message);
        }

        $c = new self;
        $c->schema($root, '', true);
        $c->resolve($root);
        if ($c->problems === []) {
            $c->cycles();
        }
        if ($c->problems !== []) {
            throw new SchemaException($c->problems);
        }

        return $root;
    }

    private function problem(string $reason, string $pointer, string $message): void
    {
        $this->problems[] = ['reason' => $reason, 'pointer' => '#'.$pointer, 'message' => $message];
    }

    /** A schema position. An empty list there is `{}` decoded as an array. */
    private function schema(mixed &$node, string $ptr, bool $root = false): void
    {
        if (is_bool($node)) {
            return;
        }
        if ($node === []) {
            $node = new JsonObject;
        }
        if (! $node instanceof JsonObject) {
            $this->problem('invalid_keyword_value', $ptr, 'a schema must be an object or a boolean, got '.self::typeName($node));

            return;
        }
        $this->objects[] = $node;

        foreach ($node->props as $k => &$value) {
            $key = (string) $k;
            $at = $ptr.'/'.Json::escape($key);

            switch ($key) {
                case '$schema':
                    if (! $root) {
                        $this->problem('unsupported_keyword', $at, '$schema below the root changes the dialect mid-document');
                    } elseif (! is_string($value)) {
                        $this->problem('invalid_keyword_value', $at, '$schema must be a string');
                    } elseif (! in_array($value, self::DIALECTS, true)) {
                        $this->problem('unsupported_dialect', $at, 'only JSON Schema 2020-12 is supported');
                    }
                    break;

                case '$id':
                    if (! $root) {
                        $this->problem('unsupported_keyword', $at, '$id below the root changes how $ref resolves');
                    } elseif (! is_string($value)) {
                        $this->problem('invalid_keyword_value', $at, '$id must be a string');
                    }
                    break;

                case '$defs':
                    $this->schemaMap($value, $at);
                    break;

                case 'properties':
                    $this->schemaMap($value, $at);
                    break;

                case '$ref':
                    if (! is_string($value)) {
                        $this->problem('invalid_keyword_value', $at, '$ref must be a string');
                        break;
                    }
                    $name = self::refName($value);
                    if ($name === null) {
                        $this->problem('unsupported_ref', $at, '$ref must be "#/$defs/<name>" in this document');
                        break;
                    }
                    $node->refPath = $value;
                    $this->refs[] = ['node' => $node, 'name' => $name, 'pointer' => $at];
                    break;

                case 'type':
                    if (! self::validType($value)) {
                        $this->problem('invalid_keyword_value', $at, 'type must be one of '.implode(', ', self::TYPES).', or a non-empty list of them without repeats');
                    }
                    break;

                case 'required':
                    if (! is_array($value) || ! self::uniqueStrings($value)) {
                        $this->problem('invalid_keyword_value', $at, 'required must be a list of distinct strings');
                    }
                    break;

                case 'additionalProperties':
                case 'not':
                    $this->schema($value, $at);
                    break;

                case 'items':
                    if (is_array($value) && $value !== []) {
                        $this->problem('invalid_keyword_value', $at, 'items must be a schema; the list form is draft-07 (prefixItems in 2020-12)');
                        break;
                    }
                    $this->schema($value, $at);
                    break;

                case 'allOf':
                case 'oneOf':
                    if (! is_array($value) || $value === []) {
                        $this->problem('invalid_keyword_value', $at, "{$key} must be a non-empty list of schemas");
                        break;
                    }
                    foreach ($value as $i => &$sub) {
                        $this->schema($sub, $at.'/'.$i);
                    }
                    unset($sub);
                    break;

                case 'enum':
                    if (! is_array($value)) {
                        $this->problem('invalid_keyword_value', $at, 'enum must be a list');
                    }
                    break;

                case 'const':
                    break;

                case 'minimum':
                case 'maximum':
                    if (! is_int($value) && ! is_float($value)) {
                        $this->problem('invalid_keyword_value', $at, "{$key} must be a number");
                    }
                    break;

                case 'minLength':
                    if (! self::nonNegativeInteger($value)) {
                        $this->problem('invalid_keyword_value', $at, 'minLength must be a non-negative integer');
                    }
                    break;

                default:
                    if (! in_array($key, self::ANNOTATIONS, true)) {
                        $this->problem('unsupported_keyword', $at, "\"{$key}\" is not a keyword this validator evaluates, so the schema is refused rather than half-applied");
                    }
            }
        }
        unset($value);
    }

    /** properties / $defs: an object whose every value is a schema. */
    private function schemaMap(mixed &$value, string $at): void
    {
        if ($value === []) {
            $value = new JsonObject;
        }
        if (! $value instanceof JsonObject) {
            $this->problem('invalid_keyword_value', $at, 'must be an object of schemas');

            return;
        }
        foreach ($value->props as $k => &$sub) {
            $this->schema($sub, $at.'/'.Json::escape((string) $k));
        }
        unset($sub);
    }

    /** Resolve every $ref against the ROOT $defs. Unresolvable is refused. */
    private function resolve(mixed $root): void
    {
        $defs = $root instanceof JsonObject && $root->has('$defs') && $root->props['$defs'] instanceof JsonObject
            ? $root->props['$defs']
            : null;

        foreach ($this->refs as ['node' => $node, 'name' => $name, 'pointer' => $pointer]) {
            if ($defs === null || ! $defs->has($name)) {
                $this->problem('unresolvable_ref', $pointer, "\$ref \"{$node->refPath}\" names no entry in the root \$defs");

                continue;
            }
            $node->hasRef = true;
            $node->refTarget = $defs->props[$name];
        }
    }

    /**
     * Refuse a loop of subschemas applied at the SAME place in the document:
     * $ref, allOf, oneOf and not. properties / items / additionalProperties
     * step into the document, so recursion through them ends when it does.
     * Iterative, so a long chain cannot exhaust the stack.
     */
    private function cycles(): void
    {
        $state = []; // id => 1 on the current path, 2 finished
        foreach ($this->objects as $start) {
            if (isset($state[spl_object_id($start)])) {
                continue;
            }
            $stack = [[$start, self::sameLocation($start), 0]];
            $state[spl_object_id($start)] = 1;
            while ($stack !== []) {
                $top = &$stack[count($stack) - 1];
                [$node, $next, $i] = $top;
                if ($i >= count($next)) {
                    $state[spl_object_id($node)] = 2;
                    array_pop($stack);
                    unset($top);

                    continue;
                }
                $top[2]++;
                $child = $next[$i];
                unset($top);
                $id = spl_object_id($child);
                if (($state[$id] ?? 0) === 1) {
                    $this->problem('ref_cycle', '', 'a $ref cycle re-enters the same schema without consuming any of the document');

                    return;
                }
                if (! isset($state[$id])) {
                    $state[$id] = 1;
                    $stack[] = [$child, self::sameLocation($child), 0];
                }
            }
        }
    }

    /** @return list<JsonObject> */
    private static function sameLocation(JsonObject $node): array
    {
        $out = [];
        if ($node->hasRef && $node->refTarget instanceof JsonObject) {
            $out[] = $node->refTarget;
        }
        foreach (['allOf', 'oneOf'] as $k) {
            foreach (($node->props[$k] ?? []) as $sub) {
                if ($sub instanceof JsonObject) {
                    $out[] = $sub;
                }
            }
        }
        if (($node->props['not'] ?? null) instanceof JsonObject) {
            $out[] = $node->props['not'];
        }

        return $out;
    }

    /** "#/$defs/<token>" -> the unescaped name, or null for anything else. */
    private static function refName(string $ref): ?string
    {
        if (! str_starts_with($ref, '#/$defs/')) {
            return null;
        }
        $token = substr($ref, 8);
        // One RFC 6901 token, no URI percent-encoding: what the JS validator
        // would read literally, a standards validator would decode. Refusing
        // it is the only answer both agree is not wrong.
        if ($token === '' || str_contains($token, '/') || str_contains($token, '%') || preg_match('/~(?![01])/', $token) === 1) {
            return null;
        }

        return str_replace(['~1', '~0'], ['/', '~'], $token);
    }

    private static function validType(mixed $value): bool
    {
        if (is_string($value)) {
            return in_array($value, self::TYPES, true);
        }
        if (! is_array($value) || $value === [] || ! self::uniqueStrings($value)) {
            return false;
        }
        foreach ($value as $t) {
            if (! in_array($t, self::TYPES, true)) {
                return false;
            }
        }

        return true;
    }

    /** @param array<mixed> $value */
    private static function uniqueStrings(array $value): bool
    {
        if (! array_is_list($value)) {
            return false;
        }
        foreach ($value as $v) {
            if (! is_string($v)) {
                return false;
            }
        }

        return count(array_unique($value)) === count($value);
    }

    private static function nonNegativeInteger(mixed $v): bool
    {
        if (is_int($v)) {
            return $v >= 0;
        }

        return is_float($v) && $v >= 0 && floor($v) === $v;
    }

    private static function typeName(mixed $v): string
    {
        return match (true) {
            $v === null => 'null',
            is_array($v) => 'array',
            is_string($v) => 'string',
            default => 'number',
        };
    }
}
