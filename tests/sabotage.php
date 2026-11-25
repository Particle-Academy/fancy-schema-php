<?php

declare(strict_types=1);

/*
 * Mutation sweep: weaken one guard at a time and require the suite to notice.
 *
 *     php tests/sabotage.php
 *
 * The suite and the implementation were written in one sitting, which is the
 * easiest way to write tests that assert only what the code already does. Each
 * mutation is a mistake a port would plausibly make; one that SURVIVES is a gap
 * in the suite. Each file is restored in a `finally`. The suite's output goes to
 * a file and the verdict is read from the exit code, never through a pipe.
 */

$root = dirname(__DIR__);
$src = $root.'/src/';

// [name, file, find, replace]; `find` must occur exactly once.
$mutations = [
    // Fail-closed compilation.
    ['ignores an unknown keyword', 'Compiler.php', 'if (! in_array($key, self::ANNOTATIONS, true)) {', 'if (false) {'],
    ['skips an unresolvable $ref', 'Compiler.php', 'if ($defs === null || ! $defs->has($name)) {', 'if ($defs === null) {'],
    ['no ref-cycle check', 'Compiler.php', '            $c->cycles();', '            // cycles skipped'],
    ['a cycle through not is not a cycle', 'Compiler.php', "if ((\$node->props['not'] ?? null) instanceof JsonObject) {", 'if (false) {'],
    ['resolves a percent-encoded $ref literally', 'Compiler.php', " || str_contains(\$token, '%')", ''],
    ['allows $id below the root', 'Compiler.php', "\$this->problem('unsupported_keyword', \$at, '\$id below the root changes how \$ref resolves');", ''],
    ['accepts any dialect', 'Compiler.php', '} elseif (! in_array($value, self::DIALECTS, true)) {', '} elseif (false) {'],
    ['accepts a malformed minLength', 'Compiler.php', 'if (! self::nonNegativeInteger($value)) {', 'if (false) {'],
    ['accepts a malformed type', 'Compiler.php', 'if (! self::validType($value)) {', 'if (false) {'],
    // Normalisation bounds.
    ['no depth bound', 'Json.php', 'if ($depth + 1 > $maxDepth) {', 'if (false) {'],
    ['no node bound', 'Json.php', 'if (++$nodes > $maxNodes) {', 'if (false) {'],
    ['accepts NaN and Infinity', 'Json.php', 'if (! is_finite($v)) {', 'if (false) {'],
    ['accepts invalid UTF-8', 'Json.php', "if (! mb_check_encoding(\$v, 'UTF-8')) {", 'if (false) {'],
    ['an empty list is an object', 'Json.php', '$isList = array_is_list($v);', '$isList = $v !== [] && array_is_list($v);'],
    // Evaluation.
    ['no evaluation budget', 'Evaluation.php', 'if (++$this->steps > $this->limits->maxSteps) {', 'if (false) {'],
    ['budget off by one', 'Evaluation.php', 'if (++$this->steps > $this->limits->maxSteps) {', 'if (++$this->steps >= $this->limits->maxSteps) {'],
    ['no evaluation depth bound', 'Evaluation.php', 'if ($depth > $this->limits->maxEvaluationDepth) {', 'if (false) {'],
    ['no maxErrors cap', 'Evaluation.php', 'if (count($this->errors) >= $this->limits->maxErrors) {', 'if (false) {'],
    ['1.0 is not an integer', 'Evaluation.php', "is_float(\$v) => floor(\$v) === \$v ? 'integer' : 'number',", "is_float(\$v) => 'number',"],
    ['an integer is not a number', 'Evaluation.php', "(\$t === 'number' && \$kind === 'integer')", 'false'],
    ['counts bytes, not code points', 'Evaluation.php', "mb_strlen(\$value, 'UTF-8')", 'strlen($value)'],
    ['minimum is exclusive', 'Evaluation.php', "\$value < \$s['minimum']", "\$value <= \$s['minimum']"],
    ['maximum is exclusive', 'Evaluation.php', "\$value > \$s['maximum']", "\$value >= \$s['maximum']"],
    ['oneOf accepts two matches', 'Evaluation.php', 'if ($matched !== 1) {', 'if ($matched === 0) {'],
    ['not is ignored', 'Evaluation.php', "if (array_key_exists('not', \$s) && \$this->passes(", "if (false && \$this->passes("],
    ['a failing branch counts as a pass', 'Evaluation.php', "        } catch (BranchFailed) {\n            return false;", "        } catch (BranchFailed) {\n            return true;"],
    ['reports only the first missing property', 'Evaluation.php', "foreach (\$s['required'] as \$key) {", "foreach (array_slice(\$s['required'], 0, 1) as \$key) {"],
    ['additionalProperties ignores properties', 'Evaluation.php', '$known[$key] = true;', ''],
    ['$ref replaces its siblings', 'Evaluation.php', "            \$this->node(\$schema->refTarget, \$value, \$ip, \$schema->refPath, \$depth + 1);\n        }", "            \$this->node(\$schema->refTarget, \$value, \$ip, \$schema->refPath, \$depth + 1);\n\n            return;\n        }"],
    ['1 is not 1.0 in const', 'Evaluation.php', 'return $numA && $numB && $a == $b;', 'return $a === $b;'],
    ['true equals 1 in const', 'Evaluation.php', 'return $numA && $numB && $a == $b;', 'return $a == $b;'],
    ['an extra key is equal', 'Evaluation.php', '|| count($a->props) !== count($b->props)) {', ') {'],
    ['items is ignored', 'Evaluation.php', "if (\$kind === 'array' && array_key_exists('items', \$s)) {", 'if (false) {'],
    ['the message echoes the property', 'Evaluation.php', "'property is not allowed', \$key);", "\"property \\\"{\$key}\\\" is not allowed\", \$key);"],
];

$suite = static function () use ($root): int {
    $log = tempnam(sys_get_temp_dir(), 'sabotage');
    $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/vendor/bin/pest').' --stop-on-failure > '.escapeshellarg($log).' 2>&1';
    $cwd = getcwd();
    chdir($root);
    exec($cmd, $out, $code);
    chdir($cwd);
    @unlink($log);

    return $code;
};

if ($suite() !== 0) {
    fwrite(STDERR, "The unmutated suite fails; fix that before reading any mutation result.\n");
    exit(1);
}

$survived = [];
foreach ($mutations as [$name, $file, $find, $replace]) {
    $path = $src.$file;
    $original = (string) file_get_contents($path);
    $normal = str_replace("\r\n", "\n", $original);
    if (substr_count($normal, $find) !== 1) {
        echo "STALE    {$name}: the text occurs ".substr_count($normal, $find)." times in {$file}\n";
        $survived[] = $name;

        continue;
    }
    try {
        file_put_contents($path, str_replace($find, $replace, $normal));
        $code = $suite();
    } finally {
        file_put_contents($path, $original);
    }
    echo ($code !== 0 ? 'caught  ' : 'SURVIVED')." {$name}\n";
    if ($code === 0) {
        $survived[] = $name;
    }
}

echo "\n".(count($mutations) - count($survived)).' of '.count($mutations)." mutations caught\n";
exit($survived === [] ? 0 : 1);
