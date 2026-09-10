<?php

declare(strict_types=1);

use Spora\Plugins\Muse\MusePlugin;
use Spora\Plugins\Muse\MuseTranscribeProvider;
use Spora\Plugins\Muse\Tools\MuseImageGenerationTool;

test('plugin entry point returns the Meta Muse name', function (): void {
    $plugin = new MusePlugin();
    expect($plugin->getName())->toBe('Meta Muse');
});

test('plugin contributes the Muse transcribe provider (STT)', function (): void {
    $plugin = new MusePlugin();
    expect($plugin->speechToTextProviders())->toBe([MuseTranscribeProvider::class]);
});

test('plugin contributes the Muse image generation tool', function (): void {
    $plugin = new MusePlugin();
    expect($plugin->tools())->toBe([MuseImageGenerationTool::class]);
});
