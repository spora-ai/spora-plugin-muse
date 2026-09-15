<?php

declare(strict_types=1);

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Spora\Plugins\Muse\MuseImageArchiveResolver;
use Spora\Plugins\Muse\Tools\MuseImageGenerationTool;
use Spora\Services\MediaArchive\MediaArchiveService;
use Spora\Services\ToolConfigService;
use Spora\Tools\ValueObjects\ToolResult;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

const TEST_DATA_URI_PNG_PREFIX = 'data:image/png;base64,';
const TEST_MIME_PNG = 'image/png';

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

/** @return string base64 bytes for a tiny PNG header. */
function fakePngB64(int $pad = 64): string
{
    return base64_encode("\x89PNG\r\n\x1a\n" . str_repeat("\x00", $pad));
}

test('generate posts to /v1/images/generations and returns the b64 image', function (): void {
    $body = json_encode([
        'created' => 1784584435,
        'data' => [['b64_json' => fakePngB64()]],
        'output_format' => 'png',
    ]);

    [$tool, $response] = buildImageTool($body);
    $result = $tool->execute(['action' => 'generate', 'prompt' => 'a cat'], agentId: 1, userId: 1);

    expect($result)->toBeInstanceOf(ToolResult::class)
        ->and($result->success)->toBeTrue()
        ->and($result->data['image_urls'])->toHaveCount(1)
        ->and($result->data['image_urls'][0])->toStartWith(TEST_DATA_URI_PNG_PREFIX)
        ->and($result->data['prompt'])->toBe('a cat');

    expect($response->getRequestUrl())->toBe('https://api.meta.ai/v1/images/generations')
        ->and($response->getRequestMethod())->toBe('POST');
});

test('generate sends {model, prompt, size} on the body, never an image_config wrapper', function (): void {
    $body = json_encode(['data' => [['b64_json' => fakePngB64(32)]]]);

    [$tool, $response] = buildImageTool($body);
    $tool->execute(
        ['action' => 'generate', 'prompt' => 'a tall cat', 'size' => '1024x1536'],
        agentId: 1,
        userId: 1,
    );

    $options = $response->getRequestOptions();
    $json = json_decode((string) ($options['body'] ?? ''), true);
    expect($json)->not->toBeNull()
        ->and($json)->not->toHaveKey('image_config')
        ->and($json)->not->toHaveKey('messages')
        ->and($json['model'])->toBe('muse-image-1.0')
        ->and($json['prompt'])->toBe('a tall cat')
        ->and($json['size'])->toBe('1024x1536');
});

test('generate honours the model ToolSetting (operator-overridable model name)', function (): void {
    $body = json_encode(['data' => [['b64_json' => fakePngB64(16)]]]);

    // Operator picks a predecessor model Meta has shipped against the
    // same API — e.g. rolling back after a bad `muse-image-1.0` release.
    [$tool, $response] = buildImageTool($body, settings: [
        'api_key' => 'sk-test',
        'model'   => 'meta/muse-image-1.0',
    ]);
    $tool->execute(['action' => 'generate', 'prompt' => 'a cat'], agentId: 1, userId: 1);

    $options = $response->getRequestOptions();
    $json = json_decode((string) ($options['body'] ?? ''), true);
    expect($json['model'])->toBe('meta/muse-image-1.0');
});

test('generate falls back to the default model when the setting is empty or whitespace', function (): void {
    $body = json_encode(['data' => [['b64_json' => fakePngB64(16)]]]);

    foreach (['', '   '] as $empty) {
        [$tool, $response] = buildImageTool($body, settings: [
            'api_key' => 'sk-test',
            'model'   => $empty,
        ]);
        $tool->execute(['action' => 'generate', 'prompt' => 'a cat'], agentId: 1, userId: 1);

        $options = $response->getRequestOptions();
        $json = json_decode((string) ($options['body'] ?? ''), true);
        expect($json['model'])->toBe('muse-image-1.0');
    }
});

test('generate normalises an unknown size to 1024x1024', function (): void {
    $body = json_encode(['data' => [['b64_json' => fakePngB64(16)]]]);

    [$tool, $response] = buildImageTool($body);
    $tool->execute(
        ['action' => 'generate', 'prompt' => 'a cat', 'size' => '9999x9999'],
        agentId: 1,
        userId: 1,
    );

    $options = $response->getRequestOptions();
    $json = json_decode((string) ($options['body'] ?? ''), true);
    expect($json['size'])->toBe('1024x1024');
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

test('edit posts to /v1/images/edits with images[] on the body, not messages', function (): void {
    $body = json_encode(['data' => [['b64_json' => fakePngB64(32)]]]);

    [$tool, $response] = buildImageTool($body);
    $result = $tool->execute(
        [
            'action' => 'edit',
            'prompt' => 'make it sunset',
            'input_images' => ['https://x.test/seed.png', TEST_DATA_URI_PNG_PREFIX . 'AAA'],
        ],
        agentId: 1,
        userId: 1,
    );

    expect($result->success)->toBeTrue();

    expect($response->getRequestUrl())->toBe('https://api.meta.ai/v1/images/edits')
        ->and($response->getRequestMethod())->toBe('POST');

    $options = $response->getRequestOptions();
    $json = json_decode((string) ($options['body'] ?? ''), true);
    expect($json)->not->toBeNull()
        ->and($json)->not->toHaveKey('messages')
        ->and($json['model'])->toBe('muse-image-1.0')
        ->and($json['prompt'])->toBe('make it sunset')
        ->and($json['images'][0]['image_url'])->toBe('https://x.test/seed.png')
        ->and($json['images'][1]['image_url'])->toBe(TEST_DATA_URI_PNG_PREFIX . 'AAA');
});

test('edit resolves a Media Archive UUID into an inline data URI on the wire', function (): void {
    $uuid = '12345678-1234-1234-1234-123456789abc';
    $png = "\x89PNG\r\n\x1a\n" . str_repeat("\x00", 32);
    $body = json_encode(['data' => [['b64_json' => fakePngB64(32)]]]);

    $config = Mockery::mock(ToolConfigService::class);
    $config->shouldReceive('getEffectiveSettings')->andReturn(['api_key' => 'sk-test']);
    $response = new MockResponse($body, ['http_code' => 200]);
    $mock = new MockHttpClient([$response]);
    $tool = new MuseImageGenerationTool($config, $mock, new NullLogger());
    $tool->setMediaArchive(null);
    $tool->setImageArchiveResolver(new MuseImageArchiveResolver(
        static fn(string $id): array => [
            'status' => 'data_url',
            'bytes'  => $png,
            'mime'   => TEST_MIME_PNG,
        ],
    ));

    $tool->execute(
        ['action' => 'edit', 'prompt' => 'make it sunset', 'input_images' => [$uuid]],
        agentId: 1,
        userId: 1,
    );

    $options = $response->getRequestOptions();
    $json = json_decode((string) ($options['body'] ?? ''), true);
    expect($json['images'])->toHaveCount(1)
        ->and($json['images'][0]['image_url'])->toBe(TEST_DATA_URI_PNG_PREFIX . base64_encode($png));
});

test('edit surfaces a failed ToolResult when a UUID does not resolve', function (): void {
    $config = Mockery::mock(ToolConfigService::class);
    $config->shouldReceive('getEffectiveSettings')->andReturn(['api_key' => 'sk-test']);
    $mock = new MockHttpClient([]);  // no requests expected
    $tool = new MuseImageGenerationTool($config, $mock, new NullLogger());
    $tool->setMediaArchive(null);
    $tool->setImageArchiveResolver(new MuseImageArchiveResolver(
        static fn(string $id): ?array => null,
    ));

    $result = $tool->execute(
        ['action' => 'edit', 'prompt' => 'make it sunset', 'input_images' => ['00000000-0000-0000-0000-000000000000']],
        agentId: 1,
        userId: 1,
    );

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('not found in the Spora Media Archive');
});

test('edit rejects when every input_images entry is empty', function (): void {
    [$tool] = buildImageTool('{}');
    $result = $tool->execute(
        ['action' => 'edit', 'prompt' => 'make it sunset', 'input_images' => ['', '   ']],
        agentId: 1,
    );
    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('at least one non-empty');
});

test('generate returns a failed ToolResult when the upstream returns 502', function (): void {
    [$tool] = buildImageTool('{"error":{"message":"upstream is sad"}}', status: 502);
    $result = $tool->execute(['action' => 'generate', 'prompt' => 'a cat'], agentId: 1);

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('Image generation failed')
        ->and($result->content)->toContain('upstream is sad');
});

test('generate returns a failed ToolResult when no images are extractable', function (): void {
    $body = json_encode(['created' => 1, 'data' => [], 'output_format' => 'webp']);
    [$tool] = buildImageTool($body);
    $result = $tool->execute(['action' => 'generate', 'prompt' => 'a cat'], agentId: 1);

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('Muse Image returned no images');
});

test('generate infers image mime from the output_format field', function (): void {
    foreach (['png' => TEST_MIME_PNG, 'webp' => 'image/webp', 'jpeg' => 'image/jpeg'] as $fmt => $mime) {
        $body = json_encode([
            'data' => [['b64_json' => fakePngB64(8)]],
            'output_format' => $fmt,
        ]);

        [$tool] = buildImageTool($body);
        $result = $tool->execute(['action' => 'generate', 'prompt' => 'a cat'], agentId: 1);

        expect($result->success)->toBeTrue()
            ->and($result->data['image_urls'][0])->toStartWith('data:' . $mime . ';base64,');
    }
});

test('describeAction() picks the right label per operation', function (): void {
    [$tool] = buildImageTool('{}');
    expect($tool->describeAction(['action' => 'generate', 'prompt' => 'a cat']))
        ->toBe("Generate image for prompt: 'a cat'");
    expect($tool->describeAction(['action' => 'edit', 'prompt' => 'a cat']))
        ->toBe("Edit image(s) with prompt: 'a cat'");
});

test('execute tolerates a null PrincipalContext (PHP 8.4 + 8.5)', function (): void {
    // Mirror the canonical AgentTool null-check in
    // vendor/spora-ai/spora-core/app/Tools/AgentTool.php:411. PHP 8.4
    // throws a fatal Error on null property access; PHP 8.5 silently
    // coerces. Either way, execute() must complete with a ToolResult.
    $body = json_encode(['data' => [['b64_json' => fakePngB64(16)]]]);
    [$tool] = buildImageTool($body);

    $result = $tool->execute(
        ['action' => 'generate', 'prompt' => 'a cat'],
        agentId: 1,
        userId: 1,
        context: null,
    );

    expect($result)->toBeInstanceOf(ToolResult::class)
        ->and($result->success)->toBeTrue();
});

test('archive() returns a data URI when MediaArchiveService throws (failure is logged, not swallowed)', function (): void {
    // `MediaArchiveService` is `final` and Mockery can't subclass it.
    // Build a real instance via reflection (skipping the ctor) so the
    // `ingest()` call lands on an uninitialised pipeline, which raises
    // a Throwable — the catch block must convert that into a logged
    // warning + a fallback data: URI (no escape to ToolInterface::execute).
    $body = json_encode(['data' => [['b64_json' => fakePngB64(16)]]]);

    $config = Mockery::mock(ToolConfigService::class);
    $config->shouldReceive('getEffectiveSettings')->andReturn(['api_key' => 'sk-test']);
    $response = new MockResponse($body, ['http_code' => 200]);
    $mock = new MockHttpClient([$response]);

    $archive = (new ReflectionClass(MediaArchiveService::class))->newInstanceWithoutConstructor();

    $logger = Mockery::mock(LoggerInterface::class);
    $logger->shouldReceive('warning')
        ->once()
        ->with('muse-image.archive-failed', Mockery::on(function (array $ctx): bool {
            return $ctx['mime'] === TEST_MIME_PNG
                && $ctx['prompt_bytes'] === 5
                && $ctx['agent_id'] === 1
                && $ctx['exception'] instanceof Throwable;
        }));

    $tool = new MuseImageGenerationTool($config, $mock, $logger);
    $tool->setMediaArchive($archive);

    $result = $tool->execute(['action' => 'generate', 'prompt' => 'a cat'], agentId: 1, userId: 1);

    expect($result->success)->toBeTrue()
        ->and($result->data['image_urls'][0])->toStartWith(TEST_DATA_URI_PNG_PREFIX);
});
