<?php

declare(strict_types=1);

namespace Spora\Plugins\Muse;

use Spora\Services\ToolConfigService;
use Spora\Speech\InvalidAudioException;
use Spora\Speech\SpeechToTextException;
use Spora\Speech\SpeechToTextProviderInterface;
use Spora\Speech\TranscriptionResult;
use Spora\Tools\Attributes\ToolSetting;
use Symfony\Component\Process\Process;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

/**
 * Meta Muse Voice Transcribe (muse-voice-transcribe-1.0) — file-based
 * transcription via https://api.meta.ai/v1/asr/transcribe.
 *
 * IMPORTANT: Meta's batch API only accepts mono 16-bit signed
 * little-endian PCM WAV at 16 or 24 kHz, up to 10 minutes / 32 MB.
 * Browser recordings (webm/opus, ogg/opus, mp4/AAC) must be transcoded
 * before submission. This provider shells out to `ffmpeg` via
 * Symfony Process — operators MUST have ffmpeg installed and on PATH
 * (or set the `ffmpeg_binary` setting to an absolute path).
 *
 * Cheaper than Mistral at scale ($0.18/hour of audio) and best English
 * streaming WER (3.06% on Artificial Analysis Sept 2026).
 *
 * Settings are read at `transcribe()` time via {@see ToolConfigService}
 * (the same pattern as MistralTranscribeProvider).
 */
#[ToolSetting(
    key: 'api_key',
    label: 'Meta Model API key',
    type: 'password',
    required: true,
    description: 'Generate at https://dev.meta.ai → API Keys. One key serves all Meta Muse capabilities (STT + Image + future).',
)]
#[ToolSetting(
    key: 'ffmpeg_binary',
    label: 'ffmpeg binary path',
    type: 'text',
    description: 'Absolute path to ffmpeg. Defaults to "ffmpeg" on PATH.',
)]
final readonly class MuseTranscribeProvider implements SpeechToTextProviderInterface
{
    private const ENDPOINT = 'https://api.meta.ai/v1/asr/transcribe';
    private const MODEL = 'muse-voice-transcribe-1.0';
    private const MAX_BYTES = 32 * 1024 * 1024;
    private const DEFAULT_FFMPEG = 'ffmpeg';

    public function __construct(
        private HttpClientInterface $http,
        private ToolConfigService $configService,
    ) {}

    public function getName(): string
    {
        return 'muse';
    }
    public function getDisplayName(): string
    {
        return 'Meta Muse Voice Transcribe';
    }

    public function isConfigured(): bool
    {
        // Optimistic — same rationale as MistralTranscribeProvider.
        return true;
    }

    public function transcribe(
        string $bytes,
        string $mimeType,
        ?string $languageHint = null,
        ?int $agentId = null,
        ?int $userId = null,
    ): TranscriptionResult {
        $settings = $this->configService->getEffectiveSettings(self::class, $agentId ?? 0, $userId);

        $apiKey = is_string($settings['api_key'] ?? null) ? trim($settings['api_key']) : '';
        if ($apiKey === '') {
            throw new SpeechToTextException('Meta Model API key is not configured for this user.');
        }

        $ffmpegBinary = is_string($settings['ffmpeg_binary'] ?? null) && trim($settings['ffmpeg_binary']) !== ''
            ? trim($settings['ffmpeg_binary'])
            : self::DEFAULT_FFMPEG;

        if (strlen($bytes) > self::MAX_BYTES) {
            throw new InvalidAudioException(sprintf(
                'Audio exceeds Meta Muse %d MB file cap.',
                self::MAX_BYTES / 1024 / 1024,
            ));
        }

        $wavPath = $this->convertToWavPcm16($bytes, $mimeType, $ffmpegBinary);
        try {
            try {
                $response = $this->http->request('POST', self::ENDPOINT, [
                    'headers' => ['Authorization' => 'Bearer ' . $apiKey],
                    'body'    => [
                        'model' => self::MODEL,
                        'file'  => fopen($wavPath, 'rb'),
                    ],
                    'timeout' => 60,
                ]);
                $payload = $response->toArray();
            } catch (Throwable $e) {
                throw new SpeechToTextException('Muse STT request failed: ' . $e->getMessage(), 0, $e);
            }
        } finally {
            @unlink($wavPath);
        }

        $text = $payload['text'] ?? null;
        if (!is_string($text) || $text === '') {
            throw new InvalidAudioException('Muse STT returned no transcript.');
        }

        return new TranscriptionResult(
            text: $text,
            language: $payload['language'] ?? $languageHint,
            durationMs: isset($payload['duration_seconds']) ? (float) $payload['duration_seconds'] * 1000.0 : null,
            metadata: array_diff_key($payload, array_flip(['text', 'language', 'duration_seconds'])),
        );
    }

    /**
     * Meta's batch endpoint only accepts mono PCM WAV. Convert any browser
     * container to that shape via ffmpeg; if the input is already that
     * shape, ffmpeg is a near-no-op passthrough.
     */
    private function convertToWavPcm16(string $bytes, string $mimeType, string $ffmpegBinary): string
    {
        $path = tempnam(sys_get_temp_dir(), 'spora-muse-') . '.wav';
        $in   = tempnam(sys_get_temp_dir(), 'spora-muse-in-') . '.' . $this->extensionFor($mimeType);
        try {
            file_put_contents($in, $bytes);

            $process = new Process([
                $ffmpegBinary, '-y', '-loglevel', 'error',
                '-i', $in,
                '-ar', '16000',
                '-ac', '1',
                '-codec:a', 'pcm_s16le',
                '-f', 'wav',
                $path,
            ]);
            $process->setTimeout(30);
            $process->run();

            if (!$process->isSuccessful()) {
                throw new InvalidAudioException('ffmpeg conversion to PCM WAV failed: ' . $process->getErrorOutput());
            }

            return $path;
        } catch (Throwable $e) {
            @unlink($path);
            throw $e;
        } finally {
            @unlink($in);
        }
    }

    private function extensionFor(string $mimeType): string
    {
        return match (true) {
            str_contains($mimeType, 'webm') => 'webm',
            str_contains($mimeType, 'ogg')  => 'ogg',
            str_contains($mimeType, 'mp4')  => 'mp4',
            str_contains($mimeType, 'mpeg') => 'mp3',
            str_contains($mimeType, 'wav')  => 'wav',
            default => throw new InvalidAudioException("Unsupported audio container: {$mimeType}"),
        };
    }
}
