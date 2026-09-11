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
 * transcription via POST https://api.meta.ai/v1/asr/transcribe.
 *
 * Meta's batch endpoint only accepts mono 16-bit signed little-endian
 * PCM WAV at 16 or 24 kHz, up to 10 minutes / 32 MB. Browser recordings
 * (webm/opus, ogg/opus, mp4/AAC) must be transcoded before submission.
 * This provider shells out to ffmpeg via Symfony Process — operators
 * MUST have ffmpeg installed and on PATH (or set the `ffmpeg_binary`
 * setting to an absolute path).
 *
 * Wire shape (verified against the Meta API):
 *   - Request: `multipart/form-data` with a `request` part (JSON blob
 *     containing `{mode, model, audioEncoding, languageBias?, keywords?}`)
 *     and an `audio` part (the WAV file).
 *   - Response: `{sessionId, transcript, audioDurationMs, turns[]}`
 *     where `turns[]` is populated only when `mode` is `ENDPOINTING`
 *     or `DIARIZATION`.
 *
 * Cheaper than Mistral at scale ($0.18/hour of audio) and best English
 * streaming WER (3.06% on Artificial Analysis Sept 2026).
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
#[ToolSetting(
    key: 'mode',
    label: 'Transcription mode',
    type: 'select',
    description: 'PUSH_TO_TALK (single-turn, default), ENDPOINTING (turn boundaries), or DIARIZATION (speaker labels).',
    default: 'PUSH_TO_TALK',
    options: ['PUSH_TO_TALK', 'ENDPOINTING', 'DIARIZATION'],
)]
#[ToolSetting(
    key: 'language_bias',
    label: 'Language bias',
    type: 'array',
    description: 'Optional list of language names to bias recognition toward (e.g. ["English", "French"]).',
)]
#[ToolSetting(
    key: 'keywords',
    label: 'Keyword bias',
    type: 'array',
    description: 'Optional list of terms to bias recognition toward (product names, jargon).',
)]
final readonly class MuseTranscribeProvider implements SpeechToTextProviderInterface
{
    private const ENDPOINT = 'https://api.meta.ai/v1/asr/transcribe';
    private const MODEL = 'muse-voice-transcribe-1.0';
    private const MAX_BYTES = 32 * 1024 * 1024;
    private const DEFAULT_FFMPEG = 'ffmpeg';
    private const DEFAULT_MODE = 'PUSH_TO_TALK';
    private const AUDIO_FILENAME = 'audio.wav';
    private const AUDIO_MIME = 'audio/wav';
    private const REQUEST_CONTENT_TYPE = 'application/json';

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

        $mode = $this->readMode($settings);
        $keywords = $this->readStringList($settings, 'keywords');
        $languageBias = $this->readStringList($settings, 'language_bias');

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
                    'multipart' => [
                        [
                            'name' => 'request',
                            'contents' => $this->buildRequestPart($mode, $keywords, $languageBias),
                            'content_type' => self::REQUEST_CONTENT_TYPE,
                        ],
                        [
                            'name' => 'audio',
                            'contents' => fopen($wavPath, 'rb'),
                            'filename' => self::AUDIO_FILENAME,
                            'content_type' => self::AUDIO_MIME,
                        ],
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

        $text = is_string($payload['transcript'] ?? null) ? trim($payload['transcript']) : '';
        if ($text === '') {
            throw new InvalidAudioException('Muse STT returned no transcript.');
        }

        $turns = is_array($payload['turns'] ?? null) ? $payload['turns'] : [];

        return new TranscriptionResult(
            text: $text,
            language: null,
            durationMs: isset($payload['audioDurationMs']) && is_numeric($payload['audioDurationMs'])
                ? (float) $payload['audioDurationMs']
                : null,
            metadata: [
                'session_id' => is_string($payload['sessionId'] ?? null) ? $payload['sessionId'] : null,
                'turns' => $turns,
                'mode' => $mode,
            ],
        );
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function readMode(array $settings): string
    {
        $mode = $settings['mode'] ?? null;
        if (!is_string($mode) || trim($mode) === '') {
            return self::DEFAULT_MODE;
        }
        return trim($mode);
    }

    /**
     * @param array<string, mixed> $settings
     * @return list<string>
     */
    private function readStringList(array $settings, string $key): array
    {
        $value = $settings[$key] ?? null;
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $entry) {
            if (is_string($entry)) {
                $trimmed = trim($entry);
                if ($trimmed !== '') {
                    $out[] = $trimmed;
                }
            }
        }
        return $out;
    }

    /**
     * @param list<string> $keywords
     * @param list<string> $languageBias
     */
    private function buildRequestPart(string $mode, array $keywords, array $languageBias): string
    {
        $payload = [
            'mode' => $mode,
            'model' => self::MODEL,
            'audioEncoding' => 'WAV',
        ];
        if ($languageBias !== []) {
            $payload['languageBias'] = $languageBias;
        }
        if ($keywords !== []) {
            $payload['keywords'] = $keywords;
        }
        return json_encode($payload, JSON_THROW_ON_ERROR);
    }

    /**
     * Meta's batch endpoint only accepts mono PCM WAV. Convert any browser
     * container to that shape via ffmpeg; if the input is already that
     * shape, ffmpeg is a near-no-op passthrough.
     *
     * Meta's recommended ffmpeg command targets 24 kHz (the model's native
     * rate) and strips metadata (`-map_metadata -1`) to avoid leaking
     * caller-context into the audio body.
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
                '-ac', '1',
                '-ar', '24000',
                '-c:a', 'pcm_s16le',
                '-map_metadata', '-1',
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
