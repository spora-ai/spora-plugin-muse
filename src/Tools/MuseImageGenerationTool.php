<?php

declare(strict_types=1);

namespace Spora\Plugins\Muse\Tools;

use Psr\Log\LoggerInterface;
use Spora\Plugins\Muse\MuseImageArchiveResolver;
use Spora\Plugins\Muse\MuseImageException;
use Spora\Plugins\Muse\MuseImageHttpClient;
use Spora\Services\MediaArchive\MediaArchiveService;
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
#[ToolSetting(key: 'model', label: 'Model', type: 'text', description: 'Meta model identifier for image generation. Default `muse-image-1.0`. Override to use a predecessor model Meta has shipped against the same API (rolling back after a bad release, A/B testing, etc.).', default: 'muse-image-1.0')]
#[ToolSetting(key: 'http_timeout_seconds', label: 'HTTP timeout (s)', type: 'number', description: 'Per-request timeout. Default 300 seconds — Muse Image returns in ~5–20 s typically; raise if editing large reference images.', default: '300')]
#[ToolParameter(name: 'prompt', type: 'string', description: 'The text prompt. Required for both `generate` and `edit`.', required: true, maximum: 32000)]
#[ToolParameter(name: 'input_images', type: 'array', items: ['type' => 'string'], description: 'Reference images for `edit`. Each item is a public URL (https/http), a data URI (data:image/png;base64,…), a Spora Media Archive UUID (xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx, with optional .ext), or an opaque /api/v1/assets/<uuid>.<ext> URL. UUIDs are resolved server-side and inlined as data URIs before the request to Meta — Meta cannot fetch Spora-local assets directly. Empty or whitespace-only entries are dropped silently. Only meaningful for `edit`.', required: false)]
#[ToolParameter(name: 'filename', type: 'string', description: 'Optional human-readable filename stem without an extension. The correct file extension is appended automatically.', required: false, maximum: 120)]
#[ToolParameter(name: 'size', type: 'string', description: 'Image size: `1024x1024` (default, square), `1024x1536` (portrait), or `1536x1024` (landscape). Invalid values fall back to the default.', required: false)]
final class MuseImageGenerationTool extends AbstractTool
{
    private const DEFAULT_TIMEOUT_SECONDS = 300;
    private const DEFAULT_MODEL = 'muse-image-1.0';
    private const MIME_FALLBACK = 'image/png';

    private ?LoggerInterface $logger;
    private ?MediaArchiveService $mediaArchive = null;
    private ?MuseImageArchiveResolver $imageArchiveResolver = null;
    private ?MuseImageArchiveService $archiveService = null;

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

    public function setImageArchiveResolver(?MuseImageArchiveResolver $imageArchiveResolver): void
    {
        $this->imageArchiveResolver = $imageArchiveResolver;
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
        if ($context === null) {
            // No PrincipalContext was supplied — the orchestrator always should
            // pass one when available. PHP 8.4 throws a fatal Error on null
            // property access; PHP 8.5 silently coerces. Mirror the canonical
            // AgentTool null-check so we work on both PHP versions.
            $ownerId  = $userId;
            $runnerId = $userId;
        } else {
            $ownerId  = $context->ownerUserId ?? $userId;
            $runnerId = $context->runnerUserId ?? $userId;
        }

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
        $failure = $this->validateGenerateArguments($arguments);
        if ($failure !== null) {
            return $failure;
        }
        return $this->runGenerateHttp($arguments, $agentId, $ownerId, $runnerId);
    }

    /** @param array<string, mixed> $arguments */
    private function edit(array $arguments, int $agentId, ?int $ownerId, ?int $runnerId): ToolResult
    {
        $failure = $this->validateEditArguments($arguments);
        if ($failure !== null) {
            return $failure;
        }
        $inputImages = $this->resolveAndFilterInputImages($arguments, $runnerId);
        if ($inputImages instanceof ToolResult) {
            return $inputImages;
        }
        return $this->dispatchEditHttp($arguments, $inputImages, $agentId, $ownerId, $runnerId);
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function validateGenerateArguments(array $arguments): ?ToolResult
    {
        if (trim((string) ($arguments['prompt'] ?? '')) === '') {
            return new ToolResult(false, 'Prompt cannot be empty.');
        }
        return null;
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function validateEditArguments(array $arguments): ?ToolResult
    {
        $prompt = trim((string) ($arguments['prompt'] ?? ''));
        $inputImages = $arguments['input_images'] ?? [];
        if ($prompt === '' || !is_array($inputImages) || $inputImages === []) {
            return new ToolResult(false, 'edit requires both `prompt` and at least one `input_images` entry.');
        }
        return null;
    }

    /**
     * Resolve Media Archive UUIDs → inline data URIs (or forward external
     * source URLs) and drop empty/whitespace entries before the Meta call.
     * Surfaces "asset not found" failures cleanly to the LLM.
     *
     * @param  array<string, mixed> $arguments
     * @return list<string>|ToolResult
     */
    private function resolveAndFilterInputImages(array $arguments, ?int $runnerId): array|ToolResult
    {
        if ($this->imageArchiveResolver !== null) {
            $resolved = $this->imageArchiveResolver->resolve($arguments, $runnerId);
            if (isset($resolved['failed'])) {
                return $resolved['failed'];
            }
            $arguments = $resolved['resolved'];
        }
        $imageUrls = [];
        foreach ($arguments['input_images'] as $entry) {
            if (!is_string($entry) || trim($entry) === '') {
                continue;
            }
            $imageUrls[] = $entry;
        }
        if ($imageUrls === []) {
            return new ToolResult(false, 'edit requires at least one non-empty `input_images` entry.');
        }
        return $imageUrls;
    }

    /** @param array<string, mixed> $arguments */
    private function runGenerateHttp(array $arguments, int $agentId, ?int $ownerId, ?int $runnerId): ToolResult
    {
        $prompt = trim((string) $arguments['prompt']);
        $client = $this->resolveClient($agentId, $ownerId);
        if ($client instanceof ToolResult) {
            return $client;
        }
        $size = MuseImageHttpClient::normaliseSize($arguments['size'] ?? null);
        try {
            $response = $client->generate($prompt, $size);
        } catch (MuseImageException $e) {
            $this->logger?->error('muse-image.generate failed', ['exception' => $e]);
            return new ToolResult(false, 'Image generation failed: ' . $e->getMessage());
        }
        return $this->renderResponse($response, $prompt, $arguments, $agentId, $runnerId, 'generate');
    }

    /**
     * @param array<string, mixed> $arguments
     * @param list<string>          $inputImages
     */
    private function dispatchEditHttp(array $arguments, array $inputImages, int $agentId, ?int $ownerId, ?int $runnerId): ToolResult
    {
        $prompt = trim((string) $arguments['prompt']);
        $client = $this->resolveClient($agentId, $ownerId);
        if ($client instanceof ToolResult) {
            return $client;
        }
        $size = MuseImageHttpClient::normaliseSize($arguments['size'] ?? null);
        try {
            $response = $client->edit($prompt, $inputImages, $size);
        } catch (MuseImageException $e) {
            $this->logger?->error('muse-image.edit failed', ['exception' => $e]);
            return new ToolResult(false, 'Image edit failed: ' . $e->getMessage());
        }
        return $this->renderResponse($response, $prompt, $arguments, $agentId, $runnerId, 'edit');
    }

    /**
     * Resolve the api_key + timeout from ToolConfigService; construct the
     * HTTP client. Returns a failed {@see ToolResult} on missing key.
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
        $model = is_string($settings['model'] ?? null) && trim($settings['model']) !== ''
            ? trim($settings['model'])
            : self::DEFAULT_MODEL;
        return new MuseImageHttpClient($this->httpClient, $apiKey, $timeout, $model);
    }

    /**
     * Walk the OpenAI-shaped images response and pull base64 image blocks.
     *
     * Response shape (both `/v1/images/generations` and `/v1/images/edits`):
     *   - `data[].b64_json` — base64-encoded image bytes (default).
     *   - `data[].url`     — temporary signed URL when `response_format: url`
     *                        is requested (not surfaced today; we only
     *                        handle the default `b64_json`).
     *
     * @param array<string, mixed> $response
     * @param array<string, mixed> $arguments
     */
    private function renderResponse(array $response, string $prompt, array $arguments, int $agentId, ?int $runnerId, string $operation): ToolResult
    {
        $imageBlocks = $this->extractImages($response);

        if ($imageBlocks === []) {
            $this->logger?->warning('muse-image response had no extractable image blocks', [
                'keys' => array_keys($response),
            ]);
            return new ToolResult(false, 'Muse Image returned no images in `data`.');
        }

        $filenameStem = isset($arguments['filename']) && is_string($arguments['filename']) && trim($arguments['filename']) !== ''
            ? trim($arguments['filename'])
            : null;

        $urls = [];
        // Meta contract guarantees one image per call; we still loop to
        // stay forward-compatible. Archive ingest failures are converted
        // into data: URI fallbacks inside `MuseImageArchiveService` so
        // the surrounding `execute()` never throws — ToolInterface
        // contractually forbids it.
        $archiveService = $this->archiveService ??= new MuseImageArchiveService($this->logger);
        foreach ($imageBlocks as $block) {
            $urls[] = $archiveService->archiveBlock(
                $this->mediaArchive,
                $block['b64'],
                $block['mime'],
                $prompt,
                $filenameStem,
                $agentId,
                $runnerId,
            );
        }

        $count = count($urls);
        $summary = $this->summarizePrompt($prompt);
        $verb = $operation === 'edit' ? 'Edited' : 'Generated';
        $heading = $count === 1
            ? "{$verb} image — {$summary}"
            : "{$verb} {$count} images — {$summary}";
        $content = $heading . "\n\n";
        $content .= implode("\n\n", array_map(
            static fn(int $i, string $url): string => MediaEmbed::image($url, "{$verb} image " . ($i + 1)),
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
        $data = $response['data'] ?? null;
        if (!is_array($data) || $data === []) {
            return [];
        }

        $blocks = [];
        $mime = $this->mimeFromOutputFormat($response['output_format'] ?? null);
        foreach ($data as $item) {
            if (!is_array($item)) {
                continue;
            }
            $b64 = $item['b64_json'] ?? null;
            if (!is_string($b64) || trim($b64) === '') {
                continue;
            }
            $itemMime = is_string($item['mime_type'] ?? null) && $item['mime_type'] !== ''
                ? $item['mime_type']
                : $mime;
            $blocks[] = ['b64' => $b64, 'mime' => $itemMime];
        }

        return $blocks;
    }

    /** @param mixed $hint */
    private function mimeFromOutputFormat(mixed $hint): string
    {
        if (!is_string($hint)) {
            return self::MIME_FALLBACK;
        }
        return match (strtolower($hint)) {
            'png' => self::MIME_FALLBACK,
            'jpeg', 'jpg' => 'image/jpeg',
            'webp' => 'image/webp',
            default => self::MIME_FALLBACK,
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
