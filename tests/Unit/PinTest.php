<?php

declare(strict_types=1);

use ParticleAcademy\FancySchema\Tests\Support\Corpus;

test('no third-party runtime dependency: only PHP and its own extensions', function () {
    $composer = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/composer.json'), true, 512, JSON_THROW_ON_ERROR);
    foreach (array_keys($composer['require']) as $name) {
        expect($name === 'php' || str_starts_with($name, 'ext-'))->toBeTrue("runtime dependency {$name} needs owner approval");
    }
});

test('CI reads the corpus from the same fancy-schema tag the tests are pinned to', function () {
    $ci = (string) file_get_contents(dirname(__DIR__, 2).'/.github/workflows/ci.yml');
    expect(preg_match('~repository: Particle-Academy/fancy-schema\s*\n\s*ref: (\S+)~', $ci, $m))->toBe(1, 'ci.yml no longer checks out fancy-schema');
    expect($m[1])->toBe(Corpus::PINNED_REF);
});

test('no source file reaches the network, the filesystem or the shell', function () {
    // A validator that fetches remote $refs fails for network reasons, and a
    // hostile schema could point it anywhere. Out of scope, by design.
    $banned = '~\b(file_get_contents|file_put_contents|fopen|curl_\w+|stream_socket_\w+|fsockopen|exec|shell_exec|proc_open|popen|passthru|system|eval|unserialize|include|include_once|require|require_once)\s*[\(\'"]~';
    $files = glob(dirname(__DIR__, 2).'/src/*.php');
    expect($files)->not->toBe([]);
    foreach ($files as $file) {
        $code = (string) file_get_contents($file);
        expect(preg_match($banned, $code, $m))->toBe(0, basename($file).' uses '.($m[1] ?? ''));
    }
});
