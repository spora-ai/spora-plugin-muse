<?php

declare(strict_types=1);

use Spora\Plugins\Muse\MuseTranscribeProvider;
use Spora\Services\ToolConfigService;
use Spora\Speech\InvalidAudioException;
use Spora\Speech\SpeechToTextException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

/**
 * Test-only HttpClient that captures the request body BEFORE Symfony's
 * `prepareRequest()` normalises it. Mirrors `OaiCapturingHttpClient` in
 * spora-core's test suite. We need the raw assoc-array body to assert
 * on field shape — after the multipart→body fix, Symfony wraps multipart
 * bodies in a generator Closure, which we don't want to assert against.
 */
final class MuseCapturingHttpClient implements HttpClientInterface
{
    public mixed $capturedBody = null;
    /** @var array<string, mixed> */
    public array $capturedHeaders = [];
    public ?string $capturedUrl = null;
    public ?string $capturedMethod = null;

    public function __construct(private HttpClientInterface $inner) {}

    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        $this->capturedBody    = $options['body'] ?? null;
        $this->capturedHeaders = $options['headers'] ?? [];
        $this->capturedUrl     = $url;
        $this->capturedMethod  = $method;
        return $this->inner->request($method, $url, $options);
    }

    public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface
    {
        return $this->inner->stream($responses, $timeout);
    }

    public function withOptions(array $options): static
    {
        $clone = clone $this;
        $clone->inner = $this->inner->withOptions($options);
        return $clone;
    }
}

/**
 * @return array{0: MuseTranscribeProvider, 1: MuseCapturingHttpClient, 2: MockResponse}
 */
function buildMuseProviderWithResponse(array $settings, string $body = '{}', int $status = 200): array
{
    $config = Mockery::mock(ToolConfigService::class);
    $config->shouldReceive('getEffectiveSettings')->andReturn($settings);

    $response = new MockResponse($body, ['http_code' => $status]);
    $mock = new MockHttpClient([$response]);
    $capturing = new MuseCapturingHttpClient($mock);
    return [new MuseTranscribeProvider($capturing, $config), $capturing, $response];
}

function buildMuseProvider(array $settings, string $body = '{}', int $status = 200): MuseTranscribeProvider
{
    [$provider] = buildMuseProviderWithResponse($settings, $body, $status);
    return $provider;
}

test('isConfigured() is optimistic', function (): void {
    $provider = buildMuseProvider([]);
    expect($provider->isConfigured())->toBeTrue();
});

test('getName / getDisplayName surface the Muse identity', function (): void {
    $provider = buildMuseProvider([]);
    expect($provider->getName())->toBe('muse')
        ->and($provider->getDisplayName())->toBe('Meta Muse Voice Transcribe');
});

test('bindLabel() overrides getDisplayName() until reset', function (): void {
    // Mirrors what SpeechToTextRegistry::describeGeneric() does for any
    // class-level provider that opts into the bindLabel() hook — the
    // resolved effective `display_name` ToolSetting is bound before
    // getDisplayName() is read.
    $provider = buildMuseProvider([]);
    $provider->bindLabel('Agent Muse Voice');
    expect($provider->getDisplayName())->toBe('Agent Muse Voice');

    // bindLabel() can rebind on every describe() call without leaking
    // across instances — the registry relies on this for multi-tenant
    // safety (one provider instance serves all requests).
    $provider->bindLabel('Group Muse Voice');
    expect($provider->getDisplayName())->toBe('Group Muse Voice');
});

test('getDisplayName() falls back to the class default when bindLabel() never fires', function (): void {
    $provider = buildMuseProvider([]);
    expect($provider->getDisplayName())->toBe('Meta Muse Voice Transcribe');
});

test('missing API key raises SpeechToTextException with a sanitised message', function (): void {
    $provider = buildMuseProvider([]);

    expect(fn() => $provider->transcribe('fake', 'audio/wav'))
        ->toThrow(SpeechToTextException::class, 'Meta Model API key is not configured');
});

test('isConfigured() returns false when bound settings lack an api_key (v2-cascade gate)', function (): void {
    // The v2 SpeechProviderConfiguration row's `api_key` is empty —
    // isConfigured() flips to false so the registry filters the
    // provider out and the transcribe endpoint returns 503 instead of
    // throwing 502 mid-call. The legacy optimistic default is kept for
    // the no-bound path so v1 `tool_user_settings` operators don't
    // regress.
    $provider = buildMuseProvider([]);
    $provider->bindSettings(['api_key' => '']);
    expect($provider->isConfigured())->toBeFalse();

    $provider->bindSettings(['api_key' => '   ']);
    expect($provider->isConfigured())->toBeFalse();

    $provider->bindSettings(['display_name' => 'no-key-here']);
    expect($provider->isConfigured())->toBeFalse();

    $provider->bindSettings(['api_key' => 'sk-from-v2']);
    expect($provider->isConfigured())->toBeTrue();
});

test('bindSettings() overrides ToolConfigService for transcribe()', function (): void {
    // The v2-cascade path: registry resolved a
    // SpeechProviderConfiguration with api_key 'sk-from-v2' and pushed
    // it into bindSettings(). transcribe() must use that key, not the
    // one ToolConfigService would have returned (which here is a stale
    // 'sk-from-v1').
    [$provider, $capturing] = buildMuseProviderWithResponse(
        ['api_key' => 'sk-from-v1'],
        json_encode([
            'sessionId'   => 's-1',
            'transcript'  => 'v2 path',
            'audioDurationMs' => 500,
            'turns'       => [],
        ]),
    );
    $provider->bindSettings(['api_key' => 'sk-from-v2']);

    $provider->transcribe(tinySilenceWav(), 'audio/wav');

    expect($capturing->capturedHeaders)->toHaveKey('Authorization')
        ->and($capturing->capturedHeaders['Authorization'])->toBe('Bearer sk-from-v2')
        ->and($capturing->capturedHeaders)->not->toContain('sk-from-v1');
});

test('bindSettings() exposes decoded settings via boundSettings() accessor', function (): void {
    $provider = buildMuseProvider([]);
    $provider->bindSettings([
        'api_key' => 'sk-x',
        'mode'    => 'DIARIZATION',
        'model'   => 'muse-voice-transcribe-1.0',
    ]);

    expect($provider->boundSettings())->toBe([
        'api_key' => 'sk-x',
        'mode'    => 'DIARIZATION',
        'model'   => 'muse-voice-transcribe-1.0',
    ]);
});

test('boundSettings() returns [] when bindSettings() never fires (legacy v1 path)', function (): void {
    $provider = buildMuseProvider([]);
    expect($provider->boundSettings())->toBe([]);
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
    [$provider, $capturing] = buildMuseProviderWithResponse(
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

    expect($capturing->capturedUrl)->toBe('https://api.meta.ai/v1/asr/transcribe')
        ->and($capturing->capturedMethod)->toBe('POST');
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

test('body contains a request blob + audio resource, headers carry Bearer', function (): void {
    // Symfony HttpClient flips `body` to `multipart/form-data` when any
    // value is a PHP resource. The plugin encodes the JSON blob as a
    // plain string field and opens the WAV temp file as a stream —
    // mirroring the OpenAI-compatible transcriber's pattern (see
    // spora-core/app/Speech/OpenAiCompatibleTranscriber.php:295).
    [$provider, $capturing] = buildMuseProviderWithResponse(
        ['api_key' => 'sk-test', 'mode' => 'PUSH_TO_TALK'],
        json_encode([
            'sessionId' => '9f1c-abc',
            'transcript' => 'hello world',
            'audioDurationMs' => 1234,
            'turns' => [],
        ]),
    );

    $provider->transcribe(tinySilenceWav(), 'audio/wav');

    $options = ['body' => $capturing->capturedBody, 'headers' => $capturing->capturedHeaders];
    $body = $options['body'] ?? null;

    expect($body)->toBeArray()
        ->and($body)->toHaveKeys(['request', 'audio']);

    // Request blob: a JSON-encoded string (NOT multipart-shaped — Symfony
    // serialises a scalar as a plain form-data text part).
    expect($body['request'])->toBeString();
    $decoded = json_decode((string) $body['request'], true);
    expect($decoded)->toBe([
        'mode' => 'PUSH_TO_TALK',
        'model' => 'muse-voice-transcribe-1.0',
        'audioEncoding' => 'WAV',
    ]);

    // Audio part: a PHP stream resource (Symfony picks the filename
    // from the stream's URI basename; Meta's API reads the bytes, not
    // the filename, so we don't pin the exact value).
    expect($body['audio'])->toBeResource();

    // The capturing client preserves the original assoc-array header
    // shape (Symfony normalises it to a list of "Header-Name: value"
    // strings internally, but we capture before that step).
    expect($options['headers'])->toHaveKey('Authorization')
        ->and($options['headers']['Authorization'])->toBe('Bearer sk-test');
});

test('model ToolSetting overrides the default in the request blob', function (): void {
    [$provider, $capturing] = buildMuseProviderWithResponse(
        ['api_key' => 'sk-test', 'model' => 'muse-voice-transcribe-0.9'],
        json_encode(['sessionId' => 'x', 'transcript' => 'hi', 'audioDurationMs' => 1, 'turns' => []]),
    );

    $provider->transcribe(tinySilenceWav(), 'audio/wav');

    $options = ['body' => $capturing->capturedBody, 'headers' => $capturing->capturedHeaders];
    $decoded = json_decode((string) ($options['body']['request'] ?? ''), true);
    expect($decoded['model'])->toBe('muse-voice-transcribe-0.9');
});

test('empty / whitespace model setting falls back to the default model', function (): void {
    foreach (['', '   '] as $empty) {
        [$provider, $capturing] = buildMuseProviderWithResponse(
            ['api_key' => 'sk-test', 'model' => $empty],
            json_encode(['sessionId' => 'x', 'transcript' => 'hi', 'audioDurationMs' => 1, 'turns' => []]),
        );

        $provider->transcribe(tinySilenceWav(), 'audio/wav');

        $options = ['body' => $capturing->capturedBody, 'headers' => $capturing->capturedHeaders];
        $decoded = json_decode((string) ($options['body']['request'] ?? ''), true);
        expect($decoded['model'])->toBe('muse-voice-transcribe-1.0');
    }
});

test('language_bias setting is serialised as languageBias in the request blob', function (): void {
    [$provider, $capturing] = buildMuseProviderWithResponse(
        ['api_key' => 'sk-test', 'mode' => 'PUSH_TO_TALK', 'language_bias' => ['English', 'French']],
        json_encode(['sessionId' => 'x', 'transcript' => 'hi', 'audioDurationMs' => 1, 'turns' => []]),
    );

    $provider->transcribe(tinySilenceWav(), 'audio/wav');

    $options = ['body' => $capturing->capturedBody, 'headers' => $capturing->capturedHeaders];
    $decoded = json_decode((string) ($options['body']['request'] ?? ''), true);
    expect($decoded['languageBias'])->toBe(['English', 'French'])
        ->and($decoded)->not->toHaveKey('language_bias');
});

test('language_bias setting decodes the JSON string the multi-select form posts', function (): void {
    // The form posts a JSON-encoded array string; in production the
    // framework decodes it via ToolConfigService::normalizeMultiSelectValues
    // before the provider reads settings. We assert the defensive
    // JSON-decode path so direct-API / unit-test callers that bypass
    // the framework still see the right list.
    [$provider, $capturing] = buildMuseProviderWithResponse(
        ['api_key' => 'sk-test', 'language_bias' => '["English","French","German"]'],
        json_encode(['sessionId' => 'x', 'transcript' => 'hi', 'audioDurationMs' => 1, 'turns' => []]),
    );

    $provider->transcribe(tinySilenceWav(), 'audio/wav');

    $options = ['body' => $capturing->capturedBody, 'headers' => $capturing->capturedHeaders];
    $decoded = json_decode((string) ($options['body']['request'] ?? ''), true);
    expect($decoded['languageBias'])->toBe(['English', 'French', 'German']);
});

test('keywords setting flows into the request blob', function (): void {
    [$provider, $capturing] = buildMuseProviderWithResponse(
        ['api_key' => 'sk-test', 'keywords' => ['Spora', 'Muse']],
        json_encode(['sessionId' => 'x', 'transcript' => 'hi', 'audioDurationMs' => 1, 'turns' => []]),
    );

    $provider->transcribe(tinySilenceWav(), 'audio/wav');

    $options = ['body' => $capturing->capturedBody, 'headers' => $capturing->capturedHeaders];
    $decoded = json_decode((string) ($options['body']['request'] ?? ''), true);
    expect($decoded['keywords'])->toBe(['Spora', 'Muse']);
});

test('keywords setting accepts comma-separated text string with whitespace', function (): void {
    [$provider, $capturing] = buildMuseProviderWithResponse(
        ['api_key' => 'sk-test', 'keywords' => 'Spora, Muse , Sporadise ,  , '],
        json_encode(['sessionId' => 'x', 'transcript' => 'hi', 'audioDurationMs' => 1, 'turns' => []]),
    );

    $provider->transcribe(tinySilenceWav(), 'audio/wav');

    $options = ['body' => $capturing->capturedBody, 'headers' => $capturing->capturedHeaders];
    $decoded = json_decode((string) ($options['body']['request'] ?? ''), true);
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

test('non-2xx with HTML body surfaces as InvalidAudioException (422), not SpeechToTextException (502)', function (): void {
    // Meta's gateway returns HTML error pages (not JSON) for some 5xx paths;
    // distinguishing a parse failure from a transport failure lets the
    // controller map the former to 422 INVALID_AUDIO (operator can fix the
    // input) instead of 502 SPEECH_PROVIDER_FAILED (provider-side issue).
    $provider = buildMuseProvider(
        ['api_key' => 'sk-test'],
        '<html>upstream gateway</html>',
        502,
    );

    expect(fn() => $provider->transcribe(tinySilenceWav(), 'audio/wav'))
        ->toThrow(InvalidAudioException::class, 'non-JSON body');
});

test('convertToWavPcm16 does not leak tempnam stubs after a successful transcribe', function (): void {
    $before = glob(sys_get_temp_dir() . '/spora-muse*') ?: [];

    [$provider] = buildMuseProviderWithResponse(
        ['api_key' => 'sk-test'],
        json_encode(['transcript' => 'hi']),
    );
    $provider->transcribe(tinySilenceWav(), 'audio/wav');

    $after = glob(sys_get_temp_dir() . '/spora-muse*') ?: [];
    expect(array_values(array_diff($after, $before)))->toBe([]);
});
