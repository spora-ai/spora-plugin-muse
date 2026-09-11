<?php

declare(strict_types=1);

namespace Spora\Plugins\Muse;

use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Thin, authenticated wrapper over Symfony's HttpClient for the Meta
 * Muse Image endpoint.
 *
 * Muse Image is exposed through Meta's OpenAI-compatible Chat Completions
 * gateway: `POST https://api.meta.ai/v1/chat/completions` with
 * `model: "muse-image"`. Image dimensions are passed via the
 * Meta-specific `image_config` field (`{image_size: "1024x1024"|"1024x1536"|"1536x1024"}`).
 *
 * Wire shape — text-to-image:
 * ```json
 * {
 *   "model": "muse-image",
 *   "messages": [{"role": "user", "content": "a cat in a top hat"}],
 *   "image_config": {"image_size": "1024x1024"}
 * }
 * ```
 *
 * Wire shape — image edit (reference images attached to the user
 * message as `image_url` content parts, per OpenAI's edit convention).
 * Meta does not document the edit wire shape directly; we follow the
 * OpenAI / LLM Gateway convention so reference images land as
 * `image_url` parts inside `messages[].content[]`.
 *
 * Response shape (per LLM Gateway docs, not directly verified in
 * dev.meta.ai — see PR notes): `choices[0].message.images[]` carrying
 * `{type: "image_url", image_url: {url: "data:image/png;base64,..."}}`.
 *
 * Single-shot: every failure surfaces to the caller so the LLM can adapt
 * on retry (smaller size, fewer reference images, raise timeout).
 */
final class MuseImageHttpClient
{
    private const ENDPOINT = 'https://api.meta.ai/v1/chat/completions';
    private const MODEL = 'muse-image';
    private const DEFAULT_SIZE = '1024x1024';
    private const ALLOWED_SIZES = ['1024x1024', '1024x1536', '1536x1024'];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $apiKey,
        private readonly int $timeoutSeconds,
    ) {}

    /**
     * @param list<array{role: string, content: string|list<array<string, mixed>>}> $messages
     * @param array<string, mixed>|null $imageConfig
     * @return array<string, mixed>
     */
    public function chat(array $messages, ?array $imageConfig = null): array
    {
        $body = ['model' => self::MODEL, 'messages' => $messages];
        if ($imageConfig !== null) {
            $body['image_config'] = $imageConfig;
        }

        try {
            $response = $this->httpClient->request('POST', self::ENDPOINT, [
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
            );
        }

        if (!is_array($decoded)) {
            throw new MuseImageException('Muse Image returned a non-JSON response.', $this->timeoutSeconds);
        }

        return $decoded;
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

    /** @param mixed $decoded */
    private function errorMessage(mixed $decoded, int $status): string
    {
        if (is_array($decoded) && is_array($decoded['error'] ?? null) && is_string($decoded['error']['message'] ?? null)) {
            return 'Muse Image returned HTTP ' . $status . ': ' . $decoded['error']['message'];
        }
        return 'Muse Image returned HTTP ' . $status . '.';
    }
}
