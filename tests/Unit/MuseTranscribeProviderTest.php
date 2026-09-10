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
    $hugeBytes = str_repeat('x', 33 * 1024 * 1024); // 33 MB

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
    $dataSize = $numSamples * 2; // 16-bit mono
    $fileSize = 36 + $dataSize;
    $header = 'RIFF' . pack('V', $fileSize) . 'WAVE';
    $header .= 'fmt ' . pack('V', 16) . pack('v', 1) . pack('v', 1); // PCM, mono
    $header .= pack('V', $sampleRate) . pack('V', $sampleRate * 2); // byte rate
    $header .= pack('v', 2) . pack('v', 16); // block align, bits per sample
    $header .= 'data' . pack('V', $dataSize) . str_repeat("\x00", $dataSize);
    return $header;
}

test('happy path returns the decoded text + language + duration_ms', function (): void {
    $provider = buildMuseProvider(
        ['api_key' => 'sk-test'],
        json_encode([
            'text'            => 'hello world',
            'language'        => 'en',
            'duration_seconds' => 4.2,
        ]),
    );

    $result = $provider->transcribe(tinySilenceWav(), 'audio/wav');

    expect($result->text)->toBe('hello world')
        ->and($result->language)->toBe('en')
        ->and($result->durationMs)->toBe(4200.0);
});

test('empty text in response raises InvalidAudioException', function (): void {
    $provider = buildMuseProvider(['api_key' => 'sk-test'], json_encode(['text' => '']));

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
