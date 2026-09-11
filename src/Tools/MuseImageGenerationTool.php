<?php

declare(strict_types=1);

namespace Spora\Plugins\Muse\Tools;

use Psr\Log\LoggerInterface;
use Spora\Plugins\Muse\MuseImageException;
use Spora\Plugins\Muse\MuseImageHttpClient;
use Spora\Plugins\Muse\MuseImagePayloadException;
use Spora\Services\MediaArchive\MediaArchiveService;
use Spora\Services\MediaArchive\MediaIngestRequest;
use Spora\Services\PrincipalContext;
use Spora\Services\ToolConfigService;
use Spora\Tools\AbstractTool;
use Spora\Tools\Attributes\Tool;
use Spora\Tools\Attributes\ToolOperation;
use Spora\Tools\Attributes\ToolParameter;
use Spora\Tools\Attributes\ToolSetting;
use Spora\Tools\MediaEmbed;
use Spora\Tools\ValueObjects\ToolResult;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

#[Tool(
    name: 'image_muse',
    description: 'Generate images from a text prompt (or edit/compose from reference images) using Meta Muse Image. $0.01/image flat. Two operations: `generate` (text-to-image, single image, no approval) and `edit` (reference images + prompt, approval-gated by default).',
    displayName: 'Muse Image',
    category: 'generation',
    icon: 'image',
)]
#[ToolOperation(name: 'generate', description: 'Generate a single image from a text prompt. Always one image.', enabledByDefault: true, requiresApprovalByDefault: false)]
#[ToolOperation(name: 'edit', description: 'Edit or compose an image from one or more reference images plus a prompt describing the desired change. Approval-gated by default — the LLM must ask the operator before issuing this call (writes reference-image bytes to Meta).', enabledByDefault: true, requiresApprovalByDefault: true)]
#[ToolSetting(key: 'api_key', label: 'Meta Model API Key', type: 'password', description: 'One key serves all Meta Muse capabilities (STT + Image + future). Generate at https://dev.meta.ai → API Keys.', required: true)]
#[ToolSetting(key: 'http_timeout_seconds', label: 'HTTP timeout (s)', type: 'number', description: 'Per-request timeout. Default 300 seconds — Muse Image returns in ~5–20 s typically; raise if editing large reference images.', default: '300')]
#[ToolParameter(name: 'prompt', type: 'string', description: 'The text prompt. Required for both `generate` and `edit`.', required: true, maximum: 32000)]
#[ToolParameter(name: 'input_images', type: 'array', description: 'Reference images for `edit`. Each item is a URL string (http/https or data: URI). Only meaningful for `edit`.', required: false)]
#[ToolParameter(name: 'filename', type: 'string', description: 'Optional human-readable filename stem without an extension. The correct file extension is appended automatically.', required: false, maximum: 120)]
#[ToolParameter(name: 'size', type: 'string', description: 'Image size: `1024x1024` (default, square), `1024x1536` (portrait), or `1536x1024` (landscape). Invalid values fall back to the default.', required: false)]
final class MuseImageGenerationTool extends AbstractTool
{
    private const DEFAULT_TIMEOUT_SECONDS = 300;
    private const MIME_FALLBACK = 'image/png';

    private ?LoggerInterface $logger;
    private ?MediaArchiveService $mediaArchive = null;

    public function __construct(
        private readonly ToolConfigService $configService,
        private readonly HttpClientInterface $httpClient,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger;
    }

    public function setMediaArchive(?MediaArchiveService $mediaArchive): void
    {
        $this->mediaArchive = $mediaArchive;
    }

    public function setLogger(?LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    public function execute(
        array $arguments,
        int $agentId,
        ?int $userId = null,
        ?int $taskId = null,
        ?PrincipalContext $context = null,
    ): ToolResult {
        $ownerId  = $context->ownerUserId ?? $userId;
        $runnerId = $context->runnerUserId ?? $userId;

        $operation = (string) ($arguments['action'] ?? 'generate');
        return match ($operation) {
            'edit'  => $this->edit($arguments, $agentId, $ownerId, $runnerId),
            default => $this->generate($arguments, $agentId, $ownerId, $runnerId),
        };
    }

    public function describeAction(array $arguments): string
    {
        $operation = (string) ($arguments['action'] ?? 'generate');
        $prompt = mb_substr(trim((string) ($arguments['prompt'] ?? '')), 0, 80);
        return match ($operation) {
            'edit'  => "Edit image(s) with prompt: '{$prompt}'",
            default => "Generate image for prompt: '{$prompt}'",
        };
    }

    /** @param array<string, mixed> $arguments */
    private function generate(array $arguments, int $agentId, ?int $ownerId, ?int $runnerId): ToolResult
    {
        $prompt = trim((string) ($arguments['prompt'] ?? ''));
        if ($prompt === '') {
            return new ToolResult(false, 'Prompt cannot be empty.');
        }

        $client = $this->resolveClient($agentId, $ownerId);
        if ($client instanceof ToolResult) {
            return $client;
        }

        $size = MuseImageHttpClient::normaliseSize($arguments['size'] ?? null);

        try {
            $response = $client->chat(
                [['role' => 'user', 'content' => $prompt]],
                ['image_size' => $size],
            );
        } catch (MuseImageException $e) {
            $this->logger?->error('muse-image.generate failed', ['exception' => $e]);
            return new ToolResult(false, 'Image generation failed: ' . $e->getMessage());
        }

        return $this->renderResponse($response, $prompt, $arguments, $agentId, $runnerId);
    }

    /** @param array<string, mixed> $arguments */
    private function edit(array $arguments, int $agentId, ?int $ownerId, ?int $runnerId): ToolResult
    {
        $prompt = trim((string) ($arguments['prompt'] ?? ''));
        $inputImages = $arguments['input_images'] ?? [];

        if ($prompt === '' || !is_array($inputImages) || $inputImages === []) {
            return new ToolResult(false, 'edit requires both `prompt` and at least one `input_images` entry.');
        }

        $client = $this->resolveClient($agentId, $ownerId);
        if ($client instanceof ToolResult) {
            return $client;
        }

        $size = MuseImageHttpClient::normaliseSize($arguments['size'] ?? null);

        $contentParts = [['type' => 'text', 'text' => $prompt]];
        foreach ($inputImages as $resolved) {
            if (!is_string($resolved) || $resolved === '') {
                continue;
            }
            $contentParts[] = $this->contentPartForImage($resolved);
        }

        try {
            $response = $client->chat(
                [['role' => 'user', 'content' => $contentParts]],
                ['image_size' => $size],
            );
        } catch (MuseImageException $e) {
            $this->logger?->error('muse-image.edit failed', ['exception' => $e]);
            return new ToolResult(false, 'Image edit failed: ' . $e->getMessage());
        }

        return $this->renderResponse($response, $prompt, $arguments, $agentId, $runnerId);
    }

    /**
     * Build an OpenAI-style image content part for the edit message.
     * Reference URLs travel as `image_url` parts (HTTP(S) or data: URIs);
     * Meta's edit wire shape isn't directly documented in dev.meta.ai
     * (those pages 500), so we follow the OpenAI / LLM Gateway edit
     * convention documented at docs.llmgateway.io/features/image-generation.
     */
    private function contentPartForImage(string $resolved): array
    {
        return ['type' => 'image_url', 'image_url' => ['url' => $resolved]];
    }

    /**
     * Resolve the api_key + timeout from ToolConfigService; construct the
     * HTTP client. Returns a failed {@see ToolResult} on missing key.
     *
     * @return MuseImageHttpClient|ToolResult
     */
    private function resolveClient(int $agentId, ?int $ownerId): MuseImageHttpClient|ToolResult
    {
        $settings = $this->configService->getEffectiveSettings(self::class, $agentId, $ownerId);
        $apiKey = is_string($settings['api_key'] ?? null) ? trim($settings['api_key']) : '';
        if ($apiKey === '') {
            return new ToolResult(false, 'Meta Model API key is not configured for this agent. '
                . 'Set the `api_key` setting on spora-plugin-muse (Muse Image tool section).');
        }
        $timeout = is_numeric($settings['http_timeout_seconds'] ?? null) && (int) $settings['http_timeout_seconds'] > 0
            ? (int) $settings['http_timeout_seconds']
            : self::DEFAULT_TIMEOUT_SECONDS;
        return new MuseImageHttpClient($this->httpClient, $apiKey, $timeout);
    }

    /**
     * Walk the chat-completions response and pull image URLs.
     *
     * Primary path (per LLM Gateway docs): `choices[0].message.images[]`
     * with `{type: "image_url", image_url: {url: "data:image/...;base64,..."}}`.
     *
     * Fallback paths (the Meta docs 500 on image endpoints, so we
     * tolerate a few plausible shapes):
     *   - `choices[0].message.images[]` with `b64_json` instead of an URL.
     *   - `choices[0].message.content` containing an embedded
     *     `data:image/...;base64,...` URL or a markdown image link.
     *
     * @param array<string, mixed> $response
     * @param array<string, mixed> $arguments
     */
    private function renderResponse(array $response, string $prompt, array $arguments, int $agentId, ?int $runnerId): ToolResult
    {
        $imageBlocks = $this->extractImages($response);

        if ($imageBlocks === []) {
            $this->logger?->warning('muse-image response had no extractable image blocks', [
                'keys' => array_keys($response),
            ]);
            return new ToolResult(false, 'Muse Image returned no images in `choices[0].message`.');
        }

        $filenameStem = isset($arguments['filename']) && is_string($arguments['filename']) && trim($arguments['filename']) !== ''
            ? trim($arguments['filename'])
            : null;

        $urls = [];
        foreach ($imageBlocks as $index => $block) {
            $filename = $filenameStem !== null && count($imageBlocks) > 1
                ? $filenameStem . '-' . ($index + 1)
                : $filenameStem;
            $urls[] = $this->archive($block['b64'], $block['mime'], $prompt, $filename, $agentId, $runnerId, $index);
        }

        $count = count($urls);
        $summary = $this->summarizePrompt($prompt);
        $heading = $count === 1
            ? "Generated image — {$summary}"
            : "Generated {$count} images — {$summary}";
        $content = $heading . "\n\n";
        $content .= implode("\n\n", array_map(
            static fn(int $i, string $url): string => MediaEmbed::image($url, 'Generated image ' . ($i + 1)),
            array_keys($urls),
            $urls,
        ));
        $content .= "\n\nEcho the markdown image block above verbatim so the chat UI renders the image inline. For raw URLs, read ToolResult.data.image_urls.";

        return new ToolResult(true, $content, ['image_urls' => $urls, 'prompt' => $prompt]);
    }

    /**
     * @param array<string, mixed> $response
     * @return list<array{b64: string, mime: string}>
     */
    private function extractImages(array $response): array
    {
        $choices = $response['choices'] ?? null;
        if (!is_array($choices) || $choices === []) {
            return [];
        }
        $first = $choices[0] ?? null;
        if (!is_array($first)) {
            return [];
        }
        $message = $first['message'] ?? null;
        if (!is_array($message)) {
            return [];
        }

        $blocks = [];

        $images = $message['images'] ?? null;
        if (is_array($images)) {
            foreach ($images as $image) {
                if (!is_array($image)) {
                    continue;
                }
                $url = $image['image_url']['url'] ?? $image['url'] ?? null;
                if (is_string($url) && $url !== '') {
                    $block = $this->decodeImageUrl($url);
                    if ($block !== null) {
                        $blocks[] = $block;
                        continue;
                    }
                }
                $b64 = $image['b64_json'] ?? $image['image_base64'] ?? null;
                if (is_string($b64) && $b64 !== '') {
                    $blocks[] = ['b64' => $b64, 'mime' => $this->guessMime($image['mime_type'] ?? null)];
                }
            }
        }

        if ($blocks !== []) {
            return $blocks;
        }

        $content = $message['content'] ?? null;
        if (is_string($content)) {
            foreach ($this->extractFromString($content) as $block) {
                $blocks[] = $block;
            }
        }

        return $blocks;
    }

    /**
     * @return list<array{b64: string, mime: string}>
     */
    private function extractFromString(string $content): array
    {
        $blocks = [];

        if (preg_match_all('#data:image/[a-zA-Z0-9.+-]+;base64,[A-Za-z0-9+/=\r\n]+#', $content, $matches) > 0) {
            foreach ($matches[0] as $uri) {
                $block = $this->decodeImageUrl($uri);
                if ($block !== null) {
                    $blocks[] = $block;
                }
            }
        }

        return $blocks;
    }

    /**
     * @return array{b64: string, mime: string}|null
     */
    private function decodeImageUrl(string $url): ?array
    {
        if (!str_starts_with($url, 'data:')) {
            return null;
        }
        $comma = strpos($url, ',');
        if ($comma === false) {
            return null;
        }
        $meta = substr($url, 5, $comma - 5);
        $b64 = substr($url, $comma + 1);
        $mime = self::MIME_FALLBACK;
        if (preg_match('#^image/[a-zA-Z0-9.+-]+#', $meta, $m) === 1) {
            $mime = $m[0];
        }
        if (trim($b64) === '') {
            return null;
        }
        return ['b64' => $b64, 'mime' => $mime];
    }

    /** @param mixed $hint */
    private function guessMime(mixed $hint): string
    {
        return is_string($hint) && $hint !== '' ? $hint : self::MIME_FALLBACK;
    }

    private function archive(string $base64, string $mime, string $prompt, ?string $filename, int $agentId, ?int $runnerId, int $index): string
    {
        $bytes = base64_decode($base64, true);
        if ($bytes === false) {
            throw new MuseImagePayloadException('Muse Image returned invalid base64 image data.');
        }
        $archive = $this->mediaArchive;
        if (!$archive instanceof MediaArchiveService) {
            return 'data:' . $mime . ';base64,' . $base64;
        }
        $ext = $this->extensionForMime($mime);
        $archiveFilename = $filename !== null
            ? $filename . '.' . $ext
            : 'muse-image-' . ($index + 1) . '.' . $ext;
        try {
            $asset = $archive->ingest(new MediaIngestRequest(
                bytes: $bytes,
                mime: $mime,
                agentId: $agentId,
                userId: $runnerId,
                pluginSlug: 'muse',
                toolName: 'image',
                prompt: $prompt,
                filename: $archiveFilename,
            ));
            return (string) $asset->asset_url;
        } catch (Throwable) {
            return 'data:' . $mime . ';base64,' . $base64;
        }
    }

    private function extensionForMime(string $mime): string
    {
        return match (true) {
            str_contains($mime, 'png')  => 'png',
            str_contains($mime, 'jpeg'),
            str_contains($mime, 'jpg')  => 'jpg',
            str_contains($mime, 'webp') => 'webp',
            default                    => 'png',
        };
    }

    private function summarizePrompt(string $prompt): string
    {
        $collapsed = preg_replace('/\s+/', ' ', trim($prompt)) ?? trim($prompt);
        if ($collapsed === '') {
            return '(empty prompt)';
        }
        if (mb_strlen($collapsed) <= 80) {
            return $collapsed;
        }
        return mb_substr($collapsed, 0, 80) . '…';
    }
}
