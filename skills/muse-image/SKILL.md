---
name: muse-image
description: 'Generate or edit images with Muse Image (`muse-image-1.0`) via Meta''s OpenAI-compatible images endpoints. Use when the user asks for an "image", "picture", "illustration", "photo", "poster", "thumbnail", "logo", "edit this photo", "restyle", "make it sunset", "remove the background", or any visual that has to be created or changed from a description (and optionally reference images). $0.01 per returned image. Two operations: `generate` (text → 1 image, no approval) and `edit` (1+ reference images + prompt, approval-gated).'
license: MIT
compatibility: spora>=0.7 spora-plugin-muse>=1.0
metadata:
  author: spora-ai
  version: "1.0"
allowed-tools: Spora\Plugins\Muse\Tools\MuseImageGenerationTool
---

# Muse Image

Generate or edit images with Meta's Muse Image (`muse-image-1.0`), wired through two OpenAI-compatible endpoints:

- `POST https://api.meta.ai/v1/images/generations` — text-to-image.
- `POST https://api.meta.ai/v1/images/edits` — image-to-image / compose from one or more reference images.

Both return `{created, data: [{b64_json}], output_format, background, usage}`. The tool archives the base64 bytes via the Media Archive (when present) and emits inline Markdown image links.

## Operations

| Action     | Approval | Endpoint                              | Reference images | Output count |
| ---------- | -------- | ------------------------------------- | ---------------- | ------------ |
| `generate` | no       | `POST /v1/images/generations`         | none             | always 1     |
| `edit`     | **yes**  | `POST /v1/images/edits`               | **required**, 1+ | 1+ (depends on `n`) |

`edit` is approval-gated by default because it sends reference-image bytes to Meta — confirm with the operator before issuing the call.

## Parameters

| Parameter      | Required | Default       | Notes |
| -------------- | -------- | ------------- | ----- |
| `action`       | no       | `generate`    | `generate` or `edit`. |
| `prompt`       | yes      | —             | Subject + style description. `edit` instructions describe the change, not the whole image. Be concrete — material, lighting, camera cues. |
| `input_images` | edit only | —           | Array of reference image URLs (https/http) or data URIs (`data:image/png;base64,…`). Empty / whitespace-only entries are dropped silently. |
| `size`         | no       | `1024x1024`   | Aspect-ratio target. `1024x1024` (square), `1024x1536` (portrait), `1536x1024` (landscape). Unknown values fall back to default. |
| `filename`     | no       | auto          | Stem only — extension is appended. Kebab-case Latin. |

### `size` — pick by surface

| Surface                              | Value        |
| ------------------------------------ | ------------ |
| Avatar, profile tile, generic preview | `1024x1024`  |
| Hero / banner / YouTube thumbnail    | `1536x1024`  |
| Phone-portrait poster                | `1024x1536`  |

When the user doesn't specify a size, **ask before guessing** if the surface is ambiguous.

## Calling

### Generate (text → 1 image)

```
image_muse(
  action: "generate",
  prompt: "a watercolor painting of a red fox sitting in a snowy pine forest, soft golden morning light",
  size: "1024x1024",
  filename: "snow-fox"
)
```

### Edit (1+ reference images → 1 image)

```
image_muse(
  action: "edit",
  prompt: "add a small red wool hat on the fox's head, keep the snowy forest background",
  input_images: ["https://example.com/seed.png", "data:image/png;base64,iVBORw0KGgo..."],
  size: "1024x1024",
  filename: "snow-fox-hatted"
)
```

## Settings (operator-scoped)

| Setting                | Default          | What it does |
| ---------------------- | ---------------- | ------------ |
| `api_key`              | — (required)     | Meta Model API key. One key serves all Muse capabilities (STT + Image). Encrypted at rest. |
| `model`                | `muse-image-1.0` | Override only to pin a predecessor model for rollback / A/B testing. |
| `http_timeout_seconds` | `300`            | Per-request timeout. Muse Image usually returns in 5–20 s; raise if editing large references. |

## Failure modes

- `Image generation failed: Muse Image returned HTTP 400: …` — Meta rejected the body. Common causes: empty `prompt`, bad `output_format`, too many reference images, or a `prompt` longer than Meta's limit. Surface the Meta error message verbatim and adapt (smaller prompt, fewer refs, etc.).
- `Muse Image returned no images in `data`.` — upstream returned an empty `data` array (safety filter, refusal, or model didn't render). Don't fabricate URLs; report the failure and ask the user how to proceed.
- `Muse Image request failed: cURL error 28: Operation timed out` — request exceeded `http_timeout_seconds`. Ask the operator to raise the setting.
- `Meta Model API key is not configured for this agent.` — `api_key` is empty at every scope. Edit the tool's settings.
- `image models only support the `image_generation` tool` (HTTP 400) — only relevant on the Responses API; you can't reach it from this tool, but flag it if you see it in the error trail.
- `edit requires both `prompt` and at least one `input_images` entry.` — missing required field for `edit`.
- `edit requires at least one non-empty `input_images` entry.` — every entry was empty/whitespace.

## Rendering

The tool already emits Markdown image embeds (`![Generated image 1: ...](https://.../api/v1/assets/<token>.<ext>)`). Echo its `content` block **verbatim** — the URL is the Media Archive asset, and rewriting the link (or stripping it) breaks the inline render. For raw URLs (download, scripting), read `ToolResult.data.image_urls` instead.

When the Media Archive plugin is absent, the tool falls back to a `data:` URI — still inline-rendering, but the bytes travel inside the chat blob. Mention the dependency if the operator asks why archived assets are missing.

## Don'ts

- **Don't fabricate URLs.** If the tool didn't return an image, say so. Don't link a stock photo or "best guess" art.
- **Don't retry on a successful call.** One generation per user request is the rule; extra calls cost $0.01 each.
- **Don't pick a size on the user's behalf when the surface is ambiguous.** A square avatar and a hero banner are not interchangeable.
- **Don't strip the "Echo the markdown image block above verbatim…" sentence.** It tells the chat UI to render the URL inline and tells future turns to keep it intact.
- **Don't use `image_config`.** That was an older Chat-Completions shape and Meta rejects it with HTTP 400 today. This tool already uses the right endpoint; the parameter doesn't exist on the current API.
