<?php

declare(strict_types=1);

use Spora\Plugins\Muse\MuseTranscribeProvider;
use Spora\Services\ToolConfigService;
use Spora\Speech\InvalidAudioException;
use Spora\Speech\SpeechToTextException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

function buildMuseProvider(array $settings, string $body = '{}', int $status = 200): MuseTranscribeProvider
{
    $config = Mockery::mock(ToolConfigService::class);
    $config->shouldReceive('getEffectiveSettings')->andReturn($settings);

    $mock = new MockHttpClient([new MockResponse($body, ['http_code' => $status])]);
    return new MuseTranscribeProvider($mock, $config);
}

/**
 * @return array{0: MuseTranscribeProvider, 1: MockResponse}
 */
function buildMuseProviderWithResponse(array $settings, string $body = '{}', int $status = 200): array
{
    $config = Mockery::mock(ToolConfigService::class);
    $config->shouldReceive('getEffectiveSettings')->andReturn($settings);

    $response = new MockResponse($body, ['http_code' => $status]);
    $mock = new MockHttpClient([$response]);
    return [new MuseTranscribeProvider($mock, $config), $response];
}

test('isConfigured() is optimistic', function (): void {
    $provider = buildMuseProvider([]);
    expect($provider->isConfigured())->toBeTrue();
});

test('getName / getDisplayName surface the Muse identity', function (): void {
    $provider = buildMuseProvider([]);
    expect($provider->getName())->toBe('muse');
    expect($provider->getDisplayName())->toBe('Meta Muse Voice Transcribe');
});

test('missing API key raises SpeechToTextException with a sanitised message', function (): void {
    $provider = buildMuseProvider([]);

    expect(fn() => $provider->transcribe('fake', 'audio/wav'))
        ->toThrow(SpeechToTextException::class, 'Meta Model API key is not configured');
});

test('audio larger than 32 MB cap raises InvalidAudioException before any work', function (): void {
    $provider = buildMuseProvider(['api_key' => 'sk-test']);
    $hugeBytes = str_repeat('x', 33 * 1024 * 1024);

    expect(fn() => $provider->transcribe($hugeBytes, 'audio/wav'))
        ->toThrow(InvalidAudioException::class, 'exceeds Meta Muse 32 MB file cap');
});

/**
 * Minimal valid PCM WAV header — 8 kHz mono 16-bit, ~0.1 s silence.
 * Real audio data follows the header (silence); ffmpeg accepts this
 * for the conversion pipeline so the test doesn't require a real ffmpeg
 * input file.
 */
function tinySilenceWav(): string
{
    $sampleRate = 8000;
    $numSamples = 800;
    $dataSize = $numSamples * 2;
    $fileSize = 36 + $dataSize;
    $header = 'RIFF' . pack('V', $fileSize) . 'WAVE';
    $header .= 'fmt ' . pack('V', 16) . pack('v', 1) . pack('v', 1);
    $header .= pack('V', $sampleRate) . pack('V', $sampleRate * 2);
    $header .= pack('v', 2) . pack('v', 16);
    $header .= 'data' . pack('V', $dataSize) . str_repeat("\x00", $dataSize);
    return $header;
}

test('happy path parses transcript + audioDurationMs + session_id from Meta wire shape', function (): void {
    [$provider, $response] = buildMuseProviderWithResponse(
        ['api_key' => 'sk-test', 'mode' => 'PUSH_TO_TALK'],
        json_encode([
            'sessionId' => '9f1c-abc',
            'transcript' => 'How is the weather? It is raining.',
            'audioDurationMs' => 8240,
            'turns' => [],
        ]),
    );

    $result = $provider->transcribe(tinySilenceWav(), 'audio/wav');

    expect($result->text)->toBe('How is the weather? It is raining.')
        ->and($result->language)->toBeNull()
        ->and($result->durationMs)->toBe(8240.0)
        ->and($result->metadata['session_id'])->toBe('9f1c-abc')
        ->and($result->metadata['mode'])->toBe('PUSH_TO_TALK')
        ->and($result->metadata['turns'])->toBe([]);

    expect($response->getRequestUrl())->toBe('https://api.meta.ai/v1/asr/transcribe')
        ->and($response->getRequestMethod())->toBe('POST');
});

test('DIARIZATION mode surfaces turns[] in metadata', function (): void {
    $provider = buildMuseProvider(
        ['api_key' => 'sk-test', 'mode' => 'DIARIZATION'],
        json_encode([
            'sessionId' => '9f1c-abc',
            'transcript' => 'How is the weather? It is raining.',
            'audioDurationMs' => 8240,
            'turns' => [
                ['turnId' => 1, 'startMs' => 1520, 'endMs' => 4640, 'transcript' => 'How is the weather?', 'speaker' => 'A'],
                ['turnId' => 2, 'startMs' => 5900, 'endMs' => 8240, 'transcript' => 'It is raining.', 'speaker' => 'B'],
            ],
        ]),
    );

    $result = $provider->transcribe(tinySilenceWav(), 'audio/wav');

    expect($result->metadata['mode'])->toBe('DIARIZATION')
        ->and($result->metadata['turns'])->toHaveCount(2)
        ->and($result->metadata['turns'][0]['speaker'])->toBe('A')
        ->and($result->metadata['turns'][1]['speaker'])->toBe('B');
});

test('request part is multipart with a JSON-encoded request blob and a file audio part', function (): void {
    [$provider, $response] = buildMuseProviderWithResponse(
        ['api_key' => 'sk-test', 'mode' => 'PUSH_TO_TALK'],
        json_encode([
            'sessionId' => '9f1c-abc',
            'transcript' => 'hello world',
            'audioDurationMs' => 1234,
            'turns' => [],
        ]),
    );

    $provider->transcribe(tinySilenceWav(), 'audio/wav');

    $options = $response->getRequestOptions();
    $multipart = $options['multipart'] ?? null;

    expect($multipart)->toBeArray()
        ->and($multipart)->toHaveCount(2);

    $requestPart = null;
    $audioPart = null;
    foreach ($multipart as $part) {
        if (($part['name'] ?? null) === 'request') {
            $requestPart = $part;
        }
        if (($part['name'] ?? null) === 'audio') {
            $audioPart = $part;
        }
    }

    expect($requestPart)->not->toBeNull()
        ->and($requestPart['content_type'] ?? null)->toBe('application/json');

    $decoded = json_decode((string) $requestPart['contents'], true);
    expect($decoded)->toBe([
        'mode' => 'PUSH_TO_TALK',
        'model' => 'muse-voice-transcribe-1.0',
        'audioEncoding' => 'WAV',
    ]);

    expect($audioPart)->not->toBeNull()
        ->and($audioPart['filename'] ?? null)->toBe('audio.wav')
        ->and($audioPart['content_type'] ?? null)->toBe('audio/wav')
        ->and($audioPart['contents'])->toBeResource();

    // Symfony HttpClient stores normalized headers as a numerically-indexed
    // list of "Header-Name: value" strings (after array_merge flattens the
    // per-header arrays). Assert via substring to keep the test stable.
    $headerBlob = implode("\n", array_map('strval', $options['headers']));
    expect($headerBlob)->toContain('Authorization: Bearer sk-test');
});

test('language_bias setting is serialised as languageBias in the request blob', function (): void {
    [$provider, $response] = buildMuseProviderWithResponse(
        ['api_key' => 'sk-test', 'mode' => 'PUSH_TO_TALK', 'language_bias' => ['English', 'French']],
        json_encode(['sessionId' => 'x', 'transcript' => 'hi', 'audioDurationMs' => 1, 'turns' => []]),
    );

    $provider->transcribe(tinySilenceWav(), 'audio/wav');

    $options = $response->getRequestOptions();
    $requestPart = null;
    foreach ($options['multipart'] as $part) {
        if (($part['name'] ?? null) === 'request') {
            $requestPart = $part;
        }
    }

    $decoded = json_decode((string) $requestPart['contents'], true);
    expect($decoded['languageBias'])->toBe(['English', 'French'])
        ->and($decoded)->not->toHaveKey('language_bias');
});

test('language_bias setting decodes the JSON string the multi-select form posts', function (): void {
    // The form posts a JSON-encoded array string; in production the
    // framework decodes it via ToolConfigService::normalizeMultiSelectValues
    // before the provider reads settings. We assert the defensive
    // JSON-decode path so direct-API / unit-test callers that bypass
    // the framework still see the right list.
    [$provider, $response] = buildMuseProviderWithResponse(
        ['api_key' => 'sk-test', 'language_bias' => '["English","French","German"]'],
        json_encode(['sessionId' => 'x', 'transcript' => 'hi', 'audioDurationMs' => 1, 'turns' => []]),
    );

    $provider->transcribe(tinySilenceWav(), 'audio/wav');

    $options = $response->getRequestOptions();
    $requestPart = null;
    foreach ($options['multipart'] as $part) {
        if (($part['name'] ?? null) === 'request') {
            $requestPart = $part;
        }
    }
    $decoded = json_decode((string) $requestPart['contents'], true);
    expect($decoded['languageBias'])->toBe(['English', 'French', 'German']);
});

test('keywords setting flows into the request blob', function (): void {
    [$provider, $response] = buildMuseProviderWithResponse(
        ['api_key' => 'sk-test', 'keywords' => ['Spora', 'Muse']],
        json_encode(['sessionId' => 'x', 'transcript' => 'hi', 'audioDurationMs' => 1, 'turns' => []]),
    );

    $provider->transcribe(tinySilenceWav(), 'audio/wav');

    $options = $response->getRequestOptions();
    $requestPart = null;
    foreach ($options['multipart'] as $part) {
        if (($part['name'] ?? null) === 'request') {
            $requestPart = $part;
        }
    }
    $decoded = json_decode((string) $requestPart['contents'], true);
    expect($decoded['keywords'])->toBe(['Spora', 'Muse']);
});

test('keywords setting accepts comma-separated text string with whitespace', function (): void {
    [$provider, $response] = buildMuseProviderWithResponse(
        ['api_key' => 'sk-test', 'keywords' => 'Spora, Muse , Sporadise ,  , '],
        json_encode(['sessionId' => 'x', 'transcript' => 'hi', 'audioDurationMs' => 1, 'turns' => []]),
    );

    $provider->transcribe(tinySilenceWav(), 'audio/wav');

    $options = $response->getRequestOptions();
    $requestPart = null;
    foreach ($options['multipart'] as $part) {
        if (($part['name'] ?? null) === 'request') {
            $requestPart = $part;
        }
    }
    $decoded = json_decode((string) $requestPart['contents'], true);
    expect($decoded['keywords'])->toBe(['Spora', 'Muse', 'Sporadise']);
});

test('empty transcript in response raises InvalidAudioException', function (): void {
    $provider = buildMuseProvider(['api_key' => 'sk-test'], json_encode(['transcript' => '']));

    expect(fn() => $provider->transcribe(tinySilenceWav(), 'audio/wav'))
        ->toThrow(InvalidAudioException::class, 'Muse STT returned no transcript.');
});

test('non-2xx HTTP status raises SpeechToTextException', function (): void {
    $provider = buildMuseProvider(['api_key' => 'sk-test'], '{"error":{"message":"unauthorized"}}', 401);

    expect(fn() => $provider->transcribe(tinySilenceWav(), 'audio/wav'))
        ->toThrow(SpeechToTextException::class);
});

test('unsupported MIME raises InvalidAudioException before ffmpeg runs', function (): void {
    $provider = buildMuseProvider(['api_key' => 'sk-test']);

    expect(fn() => $provider->transcribe('fake', 'audio/x-foo'))
        ->toThrow(InvalidAudioException::class, 'Unsupported audio container: audio/x-foo');
});

afterEach(function (): void {
    putenv('SPORA_FFMPEG_BINARY');
    unset($_ENV['SPORA_FFMPEG_BINARY']);
});

test('SPORA_FFMPEG_BINARY env var wins over bare "ffmpeg" PATH default and surfaces actionable error', function (): void {
    $_ENV['SPORA_FFMPEG_BINARY'] = '/spora/muse/test/missing-ffmpeg';
    putenv('SPORA_FFMPEG_BINARY=/spora/muse/test/missing-ffmpeg');

    $provider = buildMuseProvider(['api_key' => 'sk-test']);

    expect(fn() => $provider->transcribe(tinySilenceWav(), 'audio/wav'))
        ->toThrow(
            SpeechToTextException::class,
            'ffmpeg binary not found at "/spora/muse/test/missing-ffmpeg". Install ffmpeg '
            . '(apt-get install ffmpeg / brew install ffmpeg) or export the '
            . '`SPORA_FFMPEG_BINARY` env var to an absolute path.',
        );
});
