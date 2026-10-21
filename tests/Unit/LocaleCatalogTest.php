<?php

declare(strict_types=1);

use Spora\Plugins\Muse\MusePlugin;

$catalogDir = dirname(__DIR__, 2) . '/locales';

it('declares the locales directory through translationsPaths()', function () use ($catalogDir) {
    $paths = (new MusePlugin())->translationsPaths();

    expect($paths)->toHaveCount(1);
    expect(realpath((string) $paths[0]))->toBe(realpath($catalogDir));
});

it('ships one JSON catalogue per declared path', function () use ($catalogDir) {
    expect(is_dir($catalogDir))->toBeTrue();

    $files = glob($catalogDir . '/*.json');

    expect($files)->toBeArray();
    expect($files)->not->toBeEmpty();
});

it('holds valid JSON in every catalogue', function (string $file) {
    $messages = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);

    expect($messages)->toBeArray();
    expect($messages)->not->toBeEmpty();
    expect(array_is_list($messages))->toBeFalse();
})->with([$catalogDir . '/de.json']);

it('never leaves a message untranslated', function (string $file) {
    $messages = (array) json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);

    foreach ($messages as $english => $german) {
        expect(trim((string) $german))->not->toBe('');
        expect((string) $german)->not->toBe((string) $english);
    }
})->with([$catalogDir . '/de.json']);
