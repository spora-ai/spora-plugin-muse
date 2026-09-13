# spora-plugin-muse

Meta Muse vendor home for [Spora](https://github.com/spora-ai/spora) agents. v1 ships two capabilities sharing one Meta Model API key:

- **Muse Voice Transcribe** (`muse-voice-transcribe-1.0`) — speech-to-text via `POST https://api.meta.ai/v1/asr/transcribe`. Browser audio containers (webm/opus, ogg/opus, mp4/AAC) are transcoded to mono 16-bit PCM WAV at 24 kHz via `ffmpeg`.
- **Muse Image** (`muse-image`) — image generation + editing via Meta's Chat-Completions-compatible endpoint. Generate + edit operations, Media Archive ingest.

Future Muse capabilities (Spark text, Glimmer TTS, Muse Video) slot into this plugin as additional tools/providers — install once, get all Meta Muse capabilities.

## Installation

```bash
php bin/spora plugin:install spora-plugin-muse
php bin/spora spora:install
```

## Requirements

`ffmpeg` MUST be installed and reachable by either `$PATH` (default) or the `SPORA_FFMPEG_BINARY` env var pointing at an absolute path. The STT provider transcodes browser audio via Symfony Process. Image generation does NOT need ffmpeg.

```bash
# Debian / Ubuntu
sudo apt-get install -y ffmpeg

# macOS
brew install ffmpeg
```

## Configuration

Settings → Tools → Muse. Cascade: agent → principal → global.

| Setting                | Capability | Required | Default  | Notes |
| ---------------------- | ---------- | -------- | -------- | ----- |
| `api_key`              | both       | yes      | —        | Meta Model API key; one key serves all Muse capabilities. Generate at <https://dev.meta.ai> → API Keys. Encrypted at rest. |
| `SPORA_FFMPEG_BINARY`  | STT        | no       | `ffmpeg` | Env var override for the ffmpeg binary path (deployment-level, e.g. Docker / shared hosts). Wins over PATH. |
| `mode`                 | STT        | no       | `PUSH_TO_TALK` | `PUSH_TO_TALK` (single-turn), `ENDPOINTING` (turn boundaries), or `DIARIZATION` (speaker labels). |
| `language_bias`        | STT        | no       | (auto-detect) | Array of language names to bias toward (e.g. `["English", "French"]`). 25 validated languages. |
| `keywords`             | STT        | no       | (none)   | Array of terms to bias recognition toward (product names, jargon). |
| `http_timeout_seconds` | Image      | no       | 300      | Per-request timeout. |

## Per-tool operations

### `image_muse` — generate / edit

| Action     | Description                                              | Parameters                                                            | Approval |
| ---------- | -------------------------------------------------------- | --------------------------------------------------------------------- | -------- |
| `generate` | One image from a text prompt.                            | `prompt` (string, required), `filename` (optional stem, no ext), `size` (default `1024x1024`) | no       |
| `edit`     | Edit/compose from reference URLs/data URIs + prompt.      | `prompt` (string, required), `input_images` (array, required), `filename` (optional), `size` (default `1024x1024`) | yes |

Pricing: $0.01/image flat. Image sizes: `1024x1024` (default), `1024x1536` (portrait), `1536x1024` (landscape).

### STT (`muse-voice-transcribe-1.0`)

Pricing: $0.18/hour of audio processed. 32 MB / 10 min file cap before ffmpeg runs.

No LLM-callable surface — used internally when audio input is enabled on a Spora session. Picks the first provider that returns `isConfigured() === true`; configure one key globally and all sessions share it.

## Development

```bash
composer install
composer analyse       # PHPStan
composer test:parallel # Pest — ~22s (requires ffmpeg on PATH)
composer lint          # php-cs-fixer dry-run
composer format        # apply formatting
```

CI runs Pest on PHP 8.4 + 8.5, PHPStan, and php-cs-fixer dry-run, and installs `ffmpeg` via `apt-get` for the test job.

---

**Repo:** [spora-ai/spora-plugin-muse](https://github.com/spora-ai/spora-plugin-muse) · **MIT**
