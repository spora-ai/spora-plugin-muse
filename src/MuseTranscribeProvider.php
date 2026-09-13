<?php

declare(strict_types=1);

namespace Spora\Plugins\Muse;

use Spora\Services\ToolConfigService;
use Spora\Speech\InvalidAudioException;
use Spora\Speech\SpeechToTextException;
use Spora\Speech\SpeechToTextProviderInterface;
use Spora\Speech\TranscriptionResult;
use Spora\Tools\Attributes\ToolSetting;
use Symfony\Component\Process\Exception\ProcessStartFailedException;
use Symfony\Component\Process\ExecutableFinder;
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
 * This provider shells out to ffmpeg via Symfony Process. Operators
 * MUST either install ffmpeg on `$PATH` or export `SPORA_FFMPEG_BINARY`
 * to an absolute path. Resolution order: `SPORA_FFMPEG_BINARY` env > PATH.
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
    key: 'mode',
    label: 'Transcription mode',
    type: 'select',
    description: 'PUSH_TO_TALK (single-turn, default), ENDPOINTING (turn boundaries), or DIARIZATION (speaker labels).',
    default: 'PUSH_TO_TALK',
    options: [
        'PUSH_TO_TALK' => 'PUSH_TO_TALK (single-turn, default)',
        'ENDPOINTING' => 'ENDPOINTING (turn boundaries)',
        'DIARIZATION'  => 'DIARIZATION (speaker labels)',
    ],
)]
#[ToolSetting(
    key: 'language_bias',
    label: 'Language bias',
    type: 'multi-select',
    description: 'Biases recognition toward the picked languages. Supports code-switching — pick every language a session may produce. Meta Muse supports 25 languages total.',
    resolveAs: 'raw',
    options: [
        'English'           => 'English',
        'Arabic'            => 'Arabic',
        'Bengali'           => 'Bengali',
        'Dutch'             => 'Dutch',
        'French'            => 'French',
        'German'            => 'German',
        'Hebrew'            => 'Hebrew',
        'Hindi'             => 'Hindi',
        'Indonesian'        => 'Indonesian',
        'Italian'           => 'Italian',
        'Japanese'          => 'Japanese',
        'Kannada'           => 'Kannada',
        'Korean'            => 'Korean',
        'Malay'             => 'Malay',
        'Mandarin Chinese'  => 'Mandarin Chinese',
        'Marathi'           => 'Marathi',
        'Polish'            => 'Polish',
        'Portuguese'        => 'Portuguese',
        'Spanish'           => 'Spanish',
        'Tagalog'           => 'Tagalog',
        'Tamil'             => 'Tamil',
        'Telugu'            => 'Telugu',
        'Thai'              => 'Thai',
        'Turkish'           => 'Turkish',
        'Vietnamese'        => 'Vietnamese',
    ],
)]
#[ToolSetting(
    key: 'keywords',
    label: 'Keyword bias',
    type: 'text',
    description: 'Comma-separated list of terms to bias recognition toward (product names, jargon). e.g. Spora, Muse, Sporadise',
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

        $envBinary = (string) ($_ENV['SPORA_FFMPEG_BINARY'] ?? (getenv('SPORA_FFMPEG_BINARY') ?: ''));
        $ffmpegBinary = $envBinary !== '' ? $envBinary : self::DEFAULT_FFMPEG;

        $mode = $this->readMode($settings);
        $keywords = $this->readStringList($settings, 'keywords', ',');
        // ToolConfigService::normalizeMultiSelectValues decodes the
        // multi-select form's JSON string to an array before the provider
        // reads settings. Mirror that path defensively so direct-API
        // callers / unit tests that bypass the framework's normalization
        // still see the right list (matches
        // ToolConfigSchemaInspector::normalizeRawList's contract).
        $languageBias = $this->normalizeRawList($settings['language_bias'] ?? null);

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
     * Mirror {@see \Spora\Services\ToolConfigSchemaInspector::normalizeRawList()}:
     * accept either a JSON-encoded array string (the raw form a multi-select
     * form posts) or an already-decoded array, and return a flat list.
     *
     * Used as a safety net for direct-API callers / tests that bypass the
     * framework's setting normalization; the provider's normal flow goes
     * through {@see ToolConfigService::getEffectiveSettings()} which already
     * decodes multi-select values.
     *
     * @return list<string>
     */
    private function normalizeRawList(mixed $value): array
    {
        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : [];
        }
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
     * @param array<string, mixed> $settings
     * @return list<string>
     */
    private function readStringList(array $settings, string $key, string $separator): array
    {
        $value = $settings[$key] ?? null;
        if (is_array($value)) {
            $candidates = $value;
        } elseif (is_string($value)) {
            // Normalize Windows / Mac line endings before splitting so textarea
            // input and CLI / API callers round-trip identically regardless
            // of the caller's line-break convention.
            $candidates = explode($separator, str_replace(["\r\n", "\r"], "\n", $value));
        } else {
            return [];
        }
        $out = [];
        foreach ($candidates as $entry) {
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

            // Resolve via ExecutableFinder so the missing-binary error fires
            // before we spawn a process — Symfony's `proc_open` on macOS may
            // return a handle even for non-existent binaries (the failure
            // surfaces only at exit as opaque stderr, since Symfony's own
            // fallback at vendor/symfony/process/Process.php retries with
            // `exec <shell-command>` and still succeeds). ExecutableFinder
            // short-circuits this for both bare names on PATH and absolute
            // paths from `SPORA_FFMPEG_BINARY`.
            if ((new ExecutableFinder())->find($ffmpegBinary) === null) {
                throw new SpeechToTextException($this->missingFfmpegMessage($ffmpegBinary));
            }

            try {
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
            } catch (ProcessStartFailedException $e) {
                throw new SpeechToTextException($this->missingFfmpegMessage($ffmpegBinary), 0, $e);
            }

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

    private function missingFfmpegMessage(string $ffmpegBinary): string
    {
        return sprintf(
            'ffmpeg binary not found at "%s". Install ffmpeg '
            . '(apt-get install ffmpeg / brew install ffmpeg) or export the '
            . '`SPORA_FFMPEG_BINARY` env var to an absolute path.',
            $ffmpegBinary,
        );
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
