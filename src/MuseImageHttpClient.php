<?php

declare(strict_types=1);

namespace Spora\Plugins\Muse;

use RuntimeException;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Thin, authenticated wrapper over Symfony's HttpClient for the Meta
 * Muse Image endpoints.
 *
 * Muse Image is exposed through two OpenAI-compatible endpoints on
 * Meta's gateway:
 *   - `POST https://api.meta.ai/v1/images/generations` — text-to-image.
 *   - `POST https://api.meta.ai/v1/images/edits` — image-to-image /
 *     compose from one or more reference images.
 *
 * Both accept an OpenAI-shaped JSON body (`{model, prompt, …}` for
 * generations; `{model, prompt, images: [{image_url|file_id}]}` for
 * edits). The default model is `muse-image-1.0`; an operator can
 * override via the `model` ToolSetting on
 * {@see Tools\MuseImageGenerationTool}.
 *
 * Response shape (both endpoints): `{created, data: [{b64_json}], output_format, …}`.
 * `data[].b64_json` is the base64-encoded image bytes; we surface those
 * to the tool for ingest into the Media Archive.
 *
 * Single-shot: every failure surfaces to the caller so the LLM can
 * adapt on retry (smaller size, fewer reference images, raise timeout).
 */
final class MuseImageHttpClient
{
    private const BASE_URL = 'https://api.meta.ai/v1';
    private const DEFAULT_SIZE = '1024x1024';
    private const ALLOWED_SIZES = ['1024x1024', '1024x1536', '1536x1024'];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $apiKey,
        private readonly int $timeoutSeconds,
        private readonly string $model,
    ) {}

    /**
     * @param array<string, mixed> $extra Optional Meta-specific fields to merge
     *     into the request body (e.g. `output_format`, `tool_enablement`).
     * @return array<string, mixed>
     */
    public function generate(string $prompt, ?string $size, array $extra = []): array
    {
        $body = ['model' => $this->model, 'prompt' => $prompt];
        if ($size !== null && $size !== '') {
            $body['size'] = $size;
        }
        $body += $extra;

        return $this->post(self::BASE_URL . '/images/generations', $body);
    }

    /**
     * @param list<string> $imageUrls Public URL or data URI for each reference image.
     * @param array<string, mixed> $extra Optional Meta-specific fields to merge
     *     into the request body (e.g. `output_format`, `tool_enablement`).
     * @return array<string, mixed>
     */
    public function edit(string $prompt, array $imageUrls, ?string $size, array $extra = []): array
    {
        $body = ['model' => $this->model, 'prompt' => $prompt];
        if ($size !== null && $size !== '') {
            $body['size'] = $size;
        }
        $images = [];
        foreach ($imageUrls as $url) {
            $images[] = ['image_url' => $url];
        }
        $body['images'] = $images;
        $body += $extra;

        return $this->post(self::BASE_URL . '/images/edits', $body);
    }

    /**
     * @return string one of the {@see ALLOWED_SIZES} entries
     */
    public static function normaliseSize(mixed $size): string
    {
        if (is_string($size) && in_array($size, self::ALLOWED_SIZES, true)) {
            return $size;
        }
        return self::DEFAULT_SIZE;
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function post(string $url, array $body): array
    {
        try {
            $response = $this->httpClient->request('POST', $url, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => $body,
                'timeout' => $this->timeoutSeconds,
            ]);
        } catch (TransportExceptionInterface $e) {
            throw new MuseImageException(
                'Muse Image request failed: ' . $e->getMessage()
                . " — try fewer reference images or ask the operator to raise http_timeout_seconds (current: {$this->timeoutSeconds}s).",
                $this->timeoutSeconds,
                $e,
            );
        }

        $status = $response->getStatusCode();
        $raw = $response->getContent(false);
        $decoded = json_decode($raw, true);

        if ($status >= 400) {
            throw new MuseImageException(
                $this->errorMessage($decoded, $status),
                $this->timeoutSeconds,
                new RuntimeException('Raw response: ' . substr($raw, 0, 512)),
            );
        }

        if (!is_array($decoded)) {
            throw new MuseImageException('Muse Image returned a non-JSON response.', $this->timeoutSeconds);
        }

        return $decoded;
    }

    /** @param mixed $decoded */
    private function errorMessage(mixed $decoded, int $status): string
    {
        if (is_array($decoded) && is_array($decoded['error'] ?? null) && is_string($decoded['error']['message'] ?? null)) {
            return 'Muse Image returned HTTP ' . $status . ': ' . $decoded['error']['message'];
        }
        return 'Muse Image returned HTTP ' . $status . '.';
    }
}
