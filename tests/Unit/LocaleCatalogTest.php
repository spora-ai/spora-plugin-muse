<?php

declare(strict_types=1);

use Spora\Plugins\Muse\MusePlugin;

$catalogDir = dirname(__DIR__, 2) . '/locales';
$pluginRoot = dirname(__DIR__, 2);

/**
 * Every English literal the plugin's own source writes, decoded.
 *
 * A catalogue key is the English source string byte for byte, so a key the
 * source no longer produces is an orphan — the copy edit that was never
 * re-translated. The literals are read through the tokenizer rather than
 * searched as raw text, because the source writes an apostrophe as `\'`
 * and a raw substring search would never match the key.
 *
 * The manifest description counts as source: it is the plugin's own
 * English copy and `locales/de.json` translates it.
 *
 * @return list<string>
 */
$sourceLiterals = function (string $root): array {
    $literals = [];

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root . '/src', FilesystemIterator::SKIP_DOTS),
    );

    foreach ($files as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        foreach (token_get_all((string) file_get_contents($file->getPathname())) as $token) {
            if (!is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }

            $literal = substr($token[1], 1, -1);

            // Single-quoted PHP escapes only `\'` and `\\`; a double-quoted
            // one carries the full escape set.
            $literals[] = $token[1][0] === "'"
                ? str_replace(['\\\\', "\\'"], ['\\', "'"], $literal)
                : (string) stripcslashes($literal);
        }
    }

    $manifest = json_decode((string) file_get_contents($root . '/plugin.json'), true, 512, JSON_THROW_ON_ERROR);
    $literals[] = (string) ($manifest['description'] ?? '');

    return $literals;
};

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

it('never carries a key the source no longer produces', function () use ($pluginRoot, $sourceLiterals) {
    $messages = (array) json_decode((string) file_get_contents($pluginRoot . '/locales/de.json'), true, 512, JSON_THROW_ON_ERROR);
    $literals = $sourceLiterals($pluginRoot);

    $orphans = [];

    foreach (array_keys($messages) as $key) {
        if (!in_array((string) $key, $literals, true)) {
            $orphans[] = $key;
        }
    }

    expect($orphans)->toBe([]);
});
