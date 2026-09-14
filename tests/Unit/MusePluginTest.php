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

test('plugin exposes the plugin-root skills directory so muse-image is discovered', function (): void {
    $plugin = new MusePlugin();
    $paths = $plugin->skillPaths();
    expect($paths)->toHaveCount(1);
    expect(is_dir($paths[0]))->toBeTrue();
    expect($paths[0])->toEndWith('/skills');
});

test('plugin exposes the agent-templates directory so muse-image-expert is discovered', function (): void {
    $plugin = new MusePlugin();
    $paths = $plugin->agentTemplatePaths();
    expect($paths)->toBe([dirname(__DIR__, 2) . '/agent-templates']);
    expect(is_dir($paths[0]))->toBeTrue();
});
