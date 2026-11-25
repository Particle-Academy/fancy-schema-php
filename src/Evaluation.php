<?php

declare(strict_types=1);

namespace ParticleAcademy\FancySchema;

/**
 * One document against one compiled schema. Mirrors fancy-schema's
 * `validateNode` keyword for keyword, so the two give the same verdict and the
 * same instancePath / schemaPath / keyword on the same input.
 *
 * @internal
 */
final class Evaluation
{
    /** @var list<ValidationError> */
    private array $errors = [];

    private bool $truncated = false;

    private int $steps = 0;

    /** Above zero while a oneOf / not branch is being probed: the first error ends the probe. */
    private int $probing = 0;

    public function __construct(private readonly Limits $limits) {}

    public function run(bool|JsonObject $root, mixed $doc): Result
    {
        try {
            $this->node($root, $doc, '', '#', 0);
        } catch (StopEvaluation $stop) {
            if ($stop->error !== null) {
                $this->errors[] = $stop->error;
            }
        }

        return new Result($this->errors === [], $this->errors, $this->truncated);
    }

    private function error(string $code, string $keyword, string $ip, string $sp, string $message, ?string $detail = null): void
    {
        if ($this->probing > 0) {
            throw new BranchFailed;
        }
        if (count($this->errors) >= $this->limits->maxErrors) {
            $this->truncated = true;
            throw new StopEvaluation(null);
        }
        $this->errors[] = new ValidationError($code, $keyword, $ip, $sp, $message, $detail);
    }

    private function node(bool|JsonObject $schema, mixed $value, string $ip, string $sp, int $depth): void
    {
        if (++$this->steps > $this->limits->maxSteps) {
            throw new StopEvaluation(new ValidationError('evaluation_budget_exceeded', 'maxSteps', $ip, $sp, "evaluation exceeded maxSteps {$this->limits->maxSteps}"));
        }
        if ($depth > $this->limits->maxEvaluationDepth) {
            throw new StopEvaluation(new ValidationError('max_depth_exceeded', 'maxEvaluationDepth', $ip, $sp, "evaluation exceeded maxEvaluationDepth {$this->limits->maxEvaluationDepth}"));
        }

        if ($schema === true) {
            return;
        }
        if ($schema === false) {
            $this->error('false_schema', 'false', $ip, $sp, 'schema is `false`, so no value is valid');

            return;
        }
        $s = $schema->props;

        // In 2020-12 a $ref sits ALONGSIDE its siblings rather than replacing
        // them, so this does not return early.
        if ($schema->hasRef) {
            $this->node($schema->refTarget, $value, $ip, $schema->refPath, $depth + 1);
        }

        $kind = self::typeOf($value);

        if (array_key_exists('type', $s)) {
            $wanted = is_array($s['type']) ? $s['type'] : [$s['type']];
            $match = false;
            foreach ($wanted as $t) {
                if ($t === $kind || ($t === 'number' && $kind === 'integer')) {
                    $match = true;
                    break;
                }
            }
            if (! $match) {
                $this->error('type_mismatch', 'type', $ip, $sp.'/type', 'expected '.implode(' or ', $wanted).", got {$kind}", $kind);

                return; // every other keyword would now fail for the same reason
            }
        }

        if (array_key_exists('enum', $s)) {
            $found = false;
            foreach ($s['enum'] as $candidate) {
                if (self::equal($candidate, $value)) {
                    $found = true;
                    break;
                }
            }
            if (! $found) {
                $this->error('enum_mismatch', 'enum', $ip, $sp.'/enum', 'value is not one of the '.count($s['enum']).' allowed');
            }
        }

        if (array_key_exists('const', $s) && ! self::equal($s['const'], $value)) {
            $this->error('const_mismatch', 'const', $ip, $sp.'/const', 'value does not equal the required constant');
        }

        if ($kind === 'string' && array_key_exists('minLength', $s)) {
            // Code points, not bytes: one emoji is one character.
            $len = mb_strlen($value, 'UTF-8');
            if ($len < $s['minLength']) {
                $this->error('string_too_short', 'minLength', $ip, $sp.'/minLength', 'shorter than '.self::num($s['minLength']), (string) $len);
            }
        }

        if ($kind === 'number' || $kind === 'integer') {
            if (array_key_exists('minimum', $s) && $value < $s['minimum']) {
                $this->error('number_below_minimum', 'minimum', $ip, $sp.'/minimum', 'less than '.self::num($s['minimum']));
            }
            if (array_key_exists('maximum', $s) && $value > $s['maximum']) {
                $this->error('number_above_maximum', 'maximum', $ip, $sp.'/maximum', 'greater than '.self::num($s['maximum']));
            }
        }

        if ($kind === 'array' && array_key_exists('items', $s)) {
            foreach ($value as $i => $item) {
                $this->node($s['items'], $item, $ip.'/'.$i, $sp.'/items', $depth + 1);
            }
        }

        if ($kind === 'object') {
            /** @var JsonObject $value */
            if (array_key_exists('required', $s)) {
                // Every missing key, not just the first.
                foreach ($s['required'] as $key) {
                    if (! $value->has($key)) {
                        $this->error('required_missing', 'required', $ip, $sp.'/required', "missing required property \"{$key}\"", $key);
                    }
                }
            }

            $known = [];
            if (array_key_exists('properties', $s)) {
                foreach ($s['properties']->props as $k => $sub) {
                    $key = (string) $k;
                    $known[$key] = true;
                    if ($value->has($key)) {
                        $esc = Json::escape($key);
                        $this->node($sub, $value->props[$key], $ip.'/'.$esc, $sp.'/properties/'.$esc, $depth + 1);
                    }
                }
            }

            if (array_key_exists('additionalProperties', $s)) {
                $extra = $s['additionalProperties'];
                foreach ($value->props as $k => $item) {
                    $key = (string) $k;
                    if (isset($known[$key])) {
                        continue;
                    }
                    if ($extra === false) {
                        // Name the property in `detail` and the pointer; the
                        // message stays free of document text.
                        $this->error('additional_property', 'additionalProperties', $ip.'/'.Json::escape($key), $sp.'/additionalProperties', 'property is not allowed', $key);
                    } else {
                        $this->node($extra, $item, $ip.'/'.Json::escape($key), $sp.'/additionalProperties', $depth + 1);
                    }
                }
            }
        }

        if (array_key_exists('allOf', $s)) {
            foreach ($s['allOf'] as $i => $sub) {
                $this->node($sub, $value, $ip, $sp.'/allOf/'.$i, $depth + 1);
            }
        }

        if (array_key_exists('oneOf', $s)) {
            $matched = 0;
            foreach ($s['oneOf'] as $sub) {
                if ($this->passes($sub, $value, $depth)) {
                    $matched++;
                }
            }
            if ($matched !== 1) {
                $this->error(
                    $matched === 0 ? 'one_of_none' : 'one_of_many',
                    'oneOf',
                    $ip,
                    $sp.'/oneOf',
                    "matched {$matched} of the ".count($s['oneOf']).' alternatives, expected exactly 1',
                    (string) $matched,
                );
            }
        }

        if (array_key_exists('not', $s) && $this->passes($s['not'], $value, $depth)) {
            $this->error('not_matched', 'not', $ip, $sp.'/not', 'value matches a schema it must not match');
        }
    }

    /** Does a branch pass? Its errors are not recorded; the first one ends it. Limits still apply. */
    private function passes(bool|JsonObject $schema, mixed $value, int $depth): bool
    {
        $this->probing++;
        try {
            $this->node($schema, $value, '', '#', $depth + 1);

            return true;
        } catch (BranchFailed) {
            return false;
        } finally {
            $this->probing--;
        }
    }

    private static function typeOf(mixed $v): string
    {
        return match (true) {
            $v === null => 'null',
            is_bool($v) => 'boolean',
            is_int($v) => 'integer',
            // JSON has one number type: 1.0 is the integer 1.
            is_float($v) => floor($v) === $v ? 'integer' : 'number',
            is_string($v) => 'string',
            is_array($v) => 'array',
            default => 'object',
        };
    }

    /** Structural equality for const and enum. Key order is not significant; 1 equals 1.0. */
    private static function equal(mixed $a, mixed $b): bool
    {
        $numA = is_int($a) || is_float($a);
        $numB = is_int($b) || is_float($b);
        if ($numA || $numB) {
            return $numA && $numB && $a == $b;
        }
        if (is_array($a) || is_array($b)) {
            if (! is_array($a) || ! is_array($b) || count($a) !== count($b)) {
                return false;
            }
            foreach ($a as $i => $x) {
                if (! self::equal($x, $b[$i])) {
                    return false;
                }
            }

            return true;
        }
        if ($a instanceof JsonObject || $b instanceof JsonObject) {
            if (! $a instanceof JsonObject || ! $b instanceof JsonObject || count($a->props) !== count($b->props)) {
                return false;
            }
            foreach ($a->props as $k => $x) {
                if (! $b->has((string) $k) || ! self::equal($x, $b->props[$k])) {
                    return false;
                }
            }

            return true;
        }

        return $a === $b;
    }

    private static function num(int|float $n): string
    {
        return is_int($n) ? (string) $n : (string) json_encode($n);
    }
}
