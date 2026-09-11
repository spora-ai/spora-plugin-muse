<?php

declare(strict_types=1);

use Psr\Log\NullLogger;
use Spora\Plugins\Muse\Tools\MuseImageGenerationTool;
use Spora\Services\ToolConfigService;
use Spora\Tools\ValueObjects\ToolResult;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * @return array{0: MuseImageGenerationTool, 1: MockResponse}
 */
function buildImageTool(string $body, int $status = 200, array $settings = ['api_key' => 'sk-test']): array
{
    $config = Mockery::mock(ToolConfigService::class);
    $config->shouldReceive('getEffectiveSettings')->andReturn($settings);

    $response = new MockResponse($body, ['http_code' => $status]);
    $mock = new MockHttpClient([$response]);
    $tool = new MuseImageGenerationTool($config, $mock, new NullLogger());
    $tool->setMediaArchive(null);
    return [$tool, $response];
}

test('generate returns one image URL on the happy path (choices[].message.images[])', function (): void {
    $png = base64_encode("\x89PNG\r\n\x1a\n" . str_repeat("\x00", 64));
    $body = json_encode([
        'id' => 'chatcmpl-1',
        'choices' => [[
            'message' => [
                'role' => 'assistant',
                'content' => 'Here you go.',
                'images' => [
                    ['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,' . $png]],
                ],
            ],
            'finish_reason' => 'stop',
        ]],
    ]);

    [$tool, $response] = buildImageTool($body);
    $result = $tool->execute(['action' => 'generate', 'prompt' => 'a cat'], agentId: 1, userId: 1);

    expect($result)->toBeInstanceOf(ToolResult::class)
        ->and($result->success)->toBeTrue()
        ->and($result->data['image_urls'])->toHaveCount(1)
        ->and($result->data['image_urls'][0])->toStartWith('data:image/png;base64,')
        ->and($result->data['prompt'])->toBe('a cat');

    expect($response->getRequestUrl())->toBe('https://api.meta.ai/v1/chat/completions')
        ->and($response->getRequestMethod())->toBe('POST');
});

test('generate posts messages + image_config with the requested size to the Chat Completions endpoint', function (): void {
    $png = base64_encode("\x89PNG\r\n\x1a\n" . str_repeat("\x00", 32));
    $body = json_encode([
        'choices' => [[
            'message' => [
                'images' => [
                    ['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,' . $png]],
                ],
            ],
        ]],
    ]);

    [$tool, $response] = buildImageTool($body);
    $tool->execute(
        ['action' => 'generate', 'prompt' => 'a tall cat', 'size' => '1024x1536'],
        agentId: 1,
        userId: 1,
    );

    $options = $response->getRequestOptions();
    // Symfony normalizes `json` → `body` (JSON string) before storing request options.
    $json = json_decode((string) ($options['body'] ?? ''), true);
    expect($json)->not->toBeNull()
        ->and($json['model'])->toBe('muse-image')
        ->and($json['image_config'])->toBe(['image_size' => '1024x1536'])
        ->and($json['messages'][0]['role'])->toBe('user')
        ->and($json['messages'][0]['content'])->toBe('a tall cat');
});

test('generate fails cleanly on empty prompt', function (): void {
    [$tool] = buildImageTool('{}');
    $result = $tool->execute(['action' => 'generate', 'prompt' => '   '], agentId: 1);
    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('Prompt cannot be empty');
});

test('generate fails when API key is missing', function (): void {
    [$tool] = buildImageTool('{}', settings: []);
    $result = $tool->execute(['action' => 'generate', 'prompt' => 'a cat'], agentId: 1);
    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('Meta Model API key is not configured');
});

test('edit requires both prompt and input_images', function (): void {
    [$tool] = buildImageTool('{}');

    $missingImage = $tool->execute(['action' => 'edit', 'prompt' => 'a cat'], agentId: 1);
    expect($missingImage->success)->toBeFalse()
        ->and($missingImage->content)->toContain('requires both `prompt` and at least one');

    $missingPrompt = $tool->execute(['action' => 'edit', 'input_images' => ['https://x.test/a.png']], agentId: 1);
    expect($missingPrompt->success)->toBeFalse();
});

test('edit posts an image_url content part alongside the text prompt', function (): void {
    $png = base64_encode("\x89PNG\r\n\x1a\n" . str_repeat("\x00", 32));
    $body = json_encode([
        'choices' => [[
            'message' => [
                'images' => [
                    ['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,' . $png]],
                ],
            ],
        ]],
    ]);

    [$tool, $response] = buildImageTool($body);
    $result = $tool->execute(
        [
            'action' => 'edit',
            'prompt' => 'make it sunset',
            'input_images' => ['https://x.test/seed.png', 'data:image/png;base64,AAA'],
        ],
        agentId: 1,
        userId: 1,
    );

    expect($result->success)->toBeTrue();

    $options = $response->getRequestOptions();
    $json = json_decode((string) ($options['body'] ?? ''), true);
    $content = $json['messages'][0]['content'];
    expect($content)->toBeArray()
        ->and($content[0]['type'])->toBe('text')
        ->and($content[0]['text'])->toBe('make it sunset')
        ->and($content[1]['type'])->toBe('image_url')
        ->and($content[1]['image_url']['url'])->toBe('https://x.test/seed.png')
        ->and($content[2]['type'])->toBe('image_url')
        ->and($content[2]['image_url']['url'])->toBe('data:image/png;base64,AAA');
});

test('generate returns a failed ToolResult when the upstream returns 502', function (): void {
    [$tool] = buildImageTool('{"error":{"message":"upstream is sad"}}', status: 502);
    $result = $tool->execute(['action' => 'generate', 'prompt' => 'a cat'], agentId: 1);

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('Image generation failed')
        ->and($result->content)->toContain('upstream is sad');
});

test('generate returns a failed ToolResult when no images are extractable', function (): void {
    $body = json_encode([
        'choices' => [[
            'message' => ['role' => 'assistant', 'content' => 'Sorry, I cannot help with that.'],
            'finish_reason' => 'stop',
        ]],
    ]);
    [$tool] = buildImageTool($body);
    $result = $tool->execute(['action' => 'generate', 'prompt' => 'a cat'], agentId: 1);

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('Muse Image returned no images');
});

test('generate falls back to an embedded data URL inside message.content', function (): void {
    $png = base64_encode("\x89PNG\r\n\x1a\n" . str_repeat("\x00", 16));
    $body = json_encode([
        'choices' => [[
            'message' => [
                'role' => 'assistant',
                'content' => 'Generated. ![img](data:image/png;base64,' . $png . ')',
            ],
        ]],
    ]);

    [$tool] = buildImageTool($body);
    $result = $tool->execute(['action' => 'generate', 'prompt' => 'a cat'], agentId: 1);

    expect($result->success)->toBeTrue()
        ->and($result->data['image_urls'])->toHaveCount(1);
});

test('describeAction() picks the right label per operation', function (): void {
    [$tool] = buildImageTool('{}');
    expect($tool->describeAction(['action' => 'generate', 'prompt' => 'a cat']))
        ->toBe("Generate image for prompt: 'a cat'");
    expect($tool->describeAction(['action' => 'edit', 'prompt' => 'a cat']))
        ->toBe("Edit image(s) with prompt: 'a cat'");
});
