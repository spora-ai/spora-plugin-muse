<?php

declare(strict_types=1);

use Psr\Log\NullLogger;
use Spora\Plugins\Muse\Tools\MuseImageGenerationTool;
use Spora\Services\ToolConfigService;
use Spora\Tools\ValueObjects\ToolResult;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

function buildImageTool(string $body, int $status = 200, array $settings = ['api_key' => 'sk-test']): MuseImageGenerationTool
{
    $config = Mockery::mock(ToolConfigService::class);
    $config->shouldReceive('getEffectiveSettings')->andReturn($settings);

    $mock = new MockHttpClient([new MockResponse($body, ['http_code' => $status])]);
    $tool = new MuseImageGenerationTool($config, $mock, new NullLogger());
    $tool->setMediaArchive(null);
    return $tool;
}

test('generate returns one image URL on the happy path', function (): void {
    $png = base64_encode("\x89PNG\r\n\x1a\n" . str_repeat("\x00", 64)); // not a real PNG, just decodes
    $body = json_encode([
        'output' => [
            ['type' => 'image', 'image_base64' => $png, 'mime_type' => 'image/png'],
        ],
    ]);

    $tool = buildImageTool($body);
    $result = $tool->execute(['action' => 'generate', 'prompt' => 'a cat'], agentId: 1, userId: 1);

    expect($result)->toBeInstanceOf(ToolResult::class)
        ->and($result->success)->toBeTrue()
        ->and($result->data['image_urls'])->toHaveCount(1)
        ->and($result->data['image_urls'][0])->toStartWith('data:image/png;base64,')
        ->and($result->data['prompt'])->toBe('a cat');
});

test('generate fails cleanly on empty prompt', function (): void {
    $tool = buildImageTool('{}');
    $result = $tool->execute(['action' => 'generate', 'prompt' => '   '], agentId: 1);
    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('Prompt cannot be empty');
});

test('generate fails when API key is missing', function (): void {
    $tool = buildImageTool('{}', settings: []);
    $result = $tool->execute(['action' => 'generate', 'prompt' => 'a cat'], agentId: 1);
    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('Meta Model API key is not configured');
});

test('edit requires both prompt and input_images', function (): void {
    $tool = buildImageTool('{}');

    $missingImage = $tool->execute(['action' => 'edit', 'prompt' => 'a cat'], agentId: 1);
    expect($missingImage->success)->toBeFalse()
        ->and($missingImage->content)->toContain('requires both `prompt` and at least one');

    $missingPrompt = $tool->execute(['action' => 'edit', 'input_images' => ['https://x.test/a.png']], agentId: 1);
    expect($missingPrompt->success)->toBeFalse();
});

test('generate returns a failed ToolResult when the upstream returns 502', function (): void {
    $tool = buildImageTool('{"error":{"message":"upstream is sad"}}', status: 502);
    $result = $tool->execute(['action' => 'generate', 'prompt' => 'a cat'], agentId: 1);

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('Image generation failed')
        ->and($result->content)->toContain('upstream is sad');
});

test('generate returns a failed ToolResult when output[] is empty', function (): void {
    $tool = buildImageTool(json_encode(['output' => []]));
    $result = $tool->execute(['action' => 'generate', 'prompt' => 'a cat'], agentId: 1);

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('Muse Image returned no images');
});

test('describeAction() picks the right label per operation', function (): void {
    $tool = buildImageTool('{}');
    expect($tool->describeAction(['action' => 'generate', 'prompt' => 'a cat']))
        ->toBe("Generate image for prompt: 'a cat'");
    expect($tool->describeAction(['action' => 'edit', 'prompt' => 'a cat']))
        ->toBe("Edit image(s) with prompt: 'a cat'");
});
