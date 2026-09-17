<?php

declare(strict_types=1);

use Spora\Plugins\Muse\MuseImageArchiveResolver;
use Spora\Tools\ValueObjects\ToolResult;

const TEST_UUID = '12345678-1234-1234-1234-123456789abc';
defined('TEST_MIME_PNG') || define('TEST_MIME_PNG', 'image/png');
defined('TEST_DATA_URI_PNG_PREFIX') || define('TEST_DATA_URI_PNG_PREFIX', 'data:image/png;base64,');

function makeResolver(?Closure $reader = null): MuseImageArchiveResolver
{
    $fallback = static fn(string $id, ?int $userId): array => ['status' => 'not_found'];
    return new MuseImageArchiveResolver($reader ?? $fallback);
}

test('bare UUID resolves to an inline data URI for data_url payloads', function (): void {
    $uuid = TEST_UUID;
    $png = "\x89PNG\r\n\x1a\n" . str_repeat('x', 64);
    $resolver = makeResolver(static function (string $id, ?int $userId) use ($uuid, $png): array {
        expect($id)->toBe($uuid);
        expect($userId)->toBe(42);
        return ['status' => 'data_url', 'bytes' => $png, 'mime' => TEST_MIME_PNG];
    });

    $outcome = $resolver->resolve(
        ['action' => 'edit', 'prompt' => 'make it sunset', 'input_images' => [$uuid]],
        42,
    );

    expect($outcome)->toHaveKey('resolved');
    expect($outcome['resolved']['input_images'][0])->toBe(TEST_DATA_URI_PNG_PREFIX . base64_encode($png));
});

test('opaque /api/v1/assets/<uuid>.<ext> URL resolves to a data URI', function (): void {
    $uuid = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';
    $resolver = makeResolver(static fn(string $id): array => [
        'status' => 'local',
        'bytes'  => 'jpeg-bytes',
        'mime'   => 'image/jpeg',
    ]);

    $outcome = $resolver->resolve(
        ['input_images' => ['/api/v1/assets/' . $uuid . '.jpg']],
        1,
    );

    expect($outcome['resolved']['input_images'][0])->toBe('data:image/jpeg;base64,' . base64_encode('jpeg-bytes'));
});

test('http and data: URIs pass through untouched', function (): void {
    $readerCalled = false;
    $resolver = makeResolver(static function () use (&$readerCalled): ?array {
        $readerCalled = true;
        return null;
    });

    $outcome = $resolver->resolve([
        'input_images' => [
            'https://example.com/seed.png',
            TEST_DATA_URI_PNG_PREFIX . 'iVBORw0KGgo=',
        ],
    ], 1);

    expect($readerCalled)->toBeFalse();
    expect($outcome['resolved']['input_images'])->toBe([
        'https://example.com/seed.png',
        TEST_DATA_URI_PNG_PREFIX . 'iVBORw0KGgo=',
    ]);
});

test('mixed batch: UUID + http URL + opaque URL — each entry handled on its own', function (): void {
    $uuid = TEST_UUID;
    $resolver = makeResolver(static fn(string $id): array => [
        'status' => 'data_url',
        'bytes'  => 'png',
        'mime'   => TEST_MIME_PNG,
    ]);

    $outcome = $resolver->resolve([
        'input_images' => [
            $uuid,
            'https://example.com/keep.png',
            '/api/v1/assets/aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee.png',
        ],
    ], 7);

    expect($outcome['resolved']['input_images'])->toBe([
        TEST_DATA_URI_PNG_PREFIX . base64_encode('png'),
        'https://example.com/keep.png',
        TEST_DATA_URI_PNG_PREFIX . base64_encode('png'),
    ]);
});

test('external source_url payloads are forwarded verbatim (Meta fetches them server-side)', function (): void {
    $uuid = TEST_UUID;
    $resolver = makeResolver(static fn(string $id): array => [
        'status'    => 'external',
        'sourceUrl' => 'https://cdn.example.com/asset.png',
    ]);

    $outcome = $resolver->resolve(['input_images' => [$uuid]], 1);

    expect($outcome['resolved']['input_images'][0])->toBe('https://cdn.example.com/asset.png');
});

test('unknown UUID surfaces a failed ToolResult, not a generic error', function (): void {
    $resolver = makeResolver(static fn(string $id): ?array => null);

    $outcome = $resolver->resolve(
        ['input_images' => ['00000000-0000-0000-0000-000000000000']],
        1,
    );

    expect($outcome)->toHaveKey('failed');
    expect($outcome['failed'])->toBeInstanceOf(ToolResult::class);
    expect($outcome['failed']->success)->toBeFalse();
    expect($outcome['failed']->content)->toContain('00000000-0000-0000-0000-000000000000');
    expect($outcome['failed']->content)->toContain('not found in the Spora Media Archive');
});

test('oversize payload (over 20 MB) is rejected with a downscaling hint', function (): void {
    $oversize = str_repeat('x', 21 * 1024 * 1024);
    $resolver = makeResolver(static fn(): array => [
        'status' => 'local',
        'bytes'  => $oversize,
        'mime'   => TEST_MIME_PNG,
    ]);

    $outcome = $resolver->resolve(
        ['input_images' => [TEST_UUID]],
        1,
    );

    expect($outcome)->toHaveKey('failed');
    expect($outcome['failed']->content)->toContain('exceeds the 20 MB cap');
    expect($outcome['failed']->content)->toContain('smaller reference image');
});

test('empty / missing input_images is a no-op', function (): void {
    $resolver = makeResolver();

    expect($resolver->resolve(['prompt' => 'no refs'], 1))->toBe(['resolved' => ['prompt' => 'no refs']]);
    expect($resolver->resolve(['input_images' => []], 1))->toBe(['resolved' => ['input_images' => []]]);
    expect($resolver->resolve(['input_images' => 'not-an-array'], 1))
        ->toBe(['resolved' => ['input_images' => 'not-an-array']]);
});
