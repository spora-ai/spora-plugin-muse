<?php

declare(strict_types=1);

namespace Spora\Plugins\Muse;

use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Thin, authenticated wrapper over Symfony's HttpClient for the Meta
 * Muse Image endpoint. Sends a conversational `POST /v1/responses`
 * with model `muse-image`; parses `output[]` for image blocks.
 *
 * Single-shot: every failure surfaces to the caller so the LLM can adapt
 * on retry (smaller size, fewer reference images, raise timeout).
 *
 * Wire shape — VERIFY AT PR TIME against the live Responses API.
 * The Responses API is shared with Muse Spark text; the image content-part
 * discriminator (likely `type: "image"` with `image_base64` field, OR
 * `type: "output_image"`) needs confirmation when the PR opens.
 */
final class MuseImageHttpClient
{
    private const ENDPOINT = 'https://api.meta.ai/v1/responses';
    private const MODEL = 'muse-image';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $apiKey,
        private readonly int $timeoutSeconds,
    ) {}

    /**
     * @param list<array<string, mixed>> $userContent
     * @return array<string, mixed>
     */
    public function generate(array $userContent): array
    {
        $body = [
            'model' => self::MODEL,
            'input' => [['role' => 'user', 'content' => $userContent]],
        ];

        try {
            $response = $this->httpClient->request('POST', self::ENDPOINT, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type'  => 'application/json',
                ],
                'json'    => $body,
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

    /** @param mixed $decoded */
    private function errorMessage(mixed $decoded, int $status): string
    {
        if (is_array($decoded) && is_array($decoded['error'] ?? null) && is_string($decoded['error']['message'] ?? null)) {
            return 'Muse Image returned HTTP ' . $status . ': ' . $decoded['error']['message'];
        }
        return 'Muse Image returned HTTP ' . $status . '.';
    }
}
