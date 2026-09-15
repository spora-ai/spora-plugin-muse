# spora-plugin-muse

Meta Muse vendor home for [Spora](https://github.com/spora-ai/spora) agents. v1 ships two capabilities sharing one Meta Model API key:

- **Muse Voice Transcribe** (`muse-voice-transcribe-1.0`) — speech-to-text via `POST https://api.meta.ai/v1/asr/transcribe`. Browser audio containers (webm/opus, ogg/opus, mp4/AAC) are transcoded to mono 16-bit PCM WAV at 24 kHz via `ffmpeg`.
- **Muse Image** (`muse-image-1.0`) — image generation + editing via Meta's OpenAI-compatible `/v1/images/generations` and `/v1/images/edits` endpoints. Generate (text-to-image) + edit (image-to-image / compose) operations, Media Archive ingest.

Future Muse capabilities (Spark text, Glimmer TTS, Muse Video) slot into this plugin as additional tools/providers — install once, get all Meta Muse capabilities.

> **Pre-release notice (v1.0.0).** The plugin depends on `Spora\Speech\SpeechToTextProviderInterface` (the speech-input contract added in `spora-core` for the speech-input plan). That contract lands on `spora-core`'s `main` as part of an upcoming core release; until the first tagged core that ships the interface is published, `php bin/spora plugin:install spora-ai/spora-plugin-muse` against the released `spora-core` will fail at boot with `Class "Spora\Speech\SpeechToTextProviderInterface" not found`. To run the plugin today, install it against `spora-core`'s `main` (or the `feat/speech-input-core-contract` branch) — see [Local development](#local-development) below. The `v1.0.0` tag will be cut **after** the matching core release ships.

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
| `display_name` (STT)   | STT        | no       | `Meta Muse Voice Transcribe` | Operator-facing label surfaced in the recording-button gate, the speech provider config list, and the per-agent speech settings section. Rename per-agent / per-user to disambiguate when several STT providers are configured. |
| `model` (STT)          | STT        | no       | `muse-voice-transcribe-1.0` | Meta model identifier for STT. Override to use a predecessor model Meta has shipped against the same API (rolling back after a bad release, A/B testing, etc.). |
| `model` (Image)        | Image      | no       | `muse-image-1.0` | Meta model identifier for image generation. Same rollback / A-B rationale as the STT model. |
| `SPORA_FFMPEG_BINARY`  | STT        | no       | `ffmpeg` | Env var override for the ffmpeg binary path (deployment-level, e.g. Docker / shared hosts). Wins over PATH. |
| `mode`                 | STT        | no       | `PUSH_TO_TALK` | `PUSH_TO_TALK` (single-turn, default), `ENDPOINTING` (turn boundaries), or `DIARIZATION` (speaker labels). |
| `language_bias`        | STT        | no       | (auto-detect) | Multi-select checkboxes. Biases recognition toward the picked languages (25 supported: Arabic, Bengali, Dutch, English, French, German, Hebrew, Hindi, Indonesian, Italian, Japanese, Kannada, Korean, Malay, Mandarin Chinese, Marathi, Polish, Portuguese, Spanish, Tagalog, Tamil, Telugu, Thai, Turkish, Vietnamese). Pick multiple for code-switching sessions. |
| `keywords`             | STT        | no       | (none)   | Comma-separated list of terms to bias recognition toward (product names, jargon), e.g. `Spora, Muse, Sporadise`. |
| `http_timeout_seconds` | Image      | no       | 300      | Per-request timeout. |

## Per-tool operations

### `image_muse` — generate / edit

| Action     | Description                                              | Parameters                                                            | Approval |
| ---------- | -------------------------------------------------------- | --------------------------------------------------------------------- | -------- |
| `generate` | One image from a text prompt.                            | `prompt` (string, required), `filename` (optional stem, no ext), `size` (default `1024x1024`) | no       |
| `edit`     | Edit/compose from reference URLs/data URIs + prompt.      | `prompt` (string, required), `input_images` (array, required), `filename` (optional), `size` (default `1024x1024`) | yes |

Pricing: $0.01/image flat — billed per successfully-returned image (per Meta's docs). Image sizes: `1024x1024` (default, square), `1024x1536` (portrait), `1536x1024` (landscape). The size is a target aspect ratio; the model may produce a different exact pixel count.

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

### Local development

Clone the plugin alongside a `spora-core` checkout and let Composer resolve the in-flight core branch via a VCS repo. The plugin's `composer.json` already declares the `spora-ai/spora-core` VCS repository and aliases the `feat/speech-input-core-contract` branch as `0.24.0`, so a plain `composer install` against the dev constraint gives you the interface.

```bash
# 1. Clone side-by-side
git clone https://github.com/spora-ai/spora-core.git ../spora-core
git clone https://github.com/spora-ai/spora-plugin-muse.git ../spora-plugin-muse
cd ../spora-plugin-muse && composer install

# 2. Run the test suite (requires ffmpeg on PATH or via Spora_FFMPEG_BINARY)
composer analyse && composer test:parallel && composer lint
```

When the core release ships, update `composer.json`'s `require` block from `"dev-feat/speech-input-core-contract as 0.24.0"` to `"^0.24.0"` and drop the `repositories` entry — the v1.0.0 tag is gated on that switch.

---

**Repo:** [spora-ai/spora-plugin-muse](https://github.com/spora-ai/spora-plugin-muse) · **MIT**
