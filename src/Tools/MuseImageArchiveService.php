<?php

declare(strict_types=1);

namespace Spora\Plugins\Muse\Tools;

use Psr\Log\LoggerInterface;
use Spora\Plugins\Muse\MuseImageException;
use Spora\Plugins\Muse\MuseImagePayloadException;
use Spora\Services\MediaArchive\MediaArchiveService;
use Spora\Services\MediaArchive\MediaIngestRequest;
use Throwable;

/**
 * Encapsulates the data-URI + Media Archive ingestion pipeline for
 * {@see MuseImageGenerationTool}. Lifted out of the tool to keep the
 * tool's method count under SonarQube's 20-per-class threshold.
 *
 * Each rendered image block flows through {@see archiveBlock()} which
 * converts ingest failures (missing `MediaArchiveService`, transport
 * errors, malformed base64, etc.) into a fallback `data:` URI — the
 * tool can then return a successful {@see \Spora\Tools\ValueObjects\ToolResult}
 * without ever violating {@see \Spora\Tools\ToolInterface::execute()}'s
 * "MUST NOT throw" contract.
 *
 * The {@see MediaArchiveService} handle is taken via the constructor
 * so the {@see archiveBlock()} signature stays short (Sonar `php:S107`).
 * The tool constructs a fresh service on first use, so any subsequent
 * setter changes on the tool pick up at the next {@see archiveBlock()}
 * call.
 */
final class MuseImageArchiveService
{
    private const MIME_FALLBACK = 'image/png';
    private const DATA_URI_BASE64_PREFIX = ';base64,';

    public function __construct(
        private readonly ?LoggerInterface $logger = null,
        private readonly ?MediaArchiveService $archive = null,
    ) {}

    /**
     * Convert one rendered image block to a public URL. On any failure
     * (transport, ingest, malformed bytes, even unexpected errors) the
     * call still resolves to a `data:` URI so the surrounding tool
     * returns a successful {@see \Spora\Tools\ValueObjects\ToolResult}.
     */
    public function archiveBlock(
        string $base64,
        string $mime,
        string $prompt,
        ?string $filename,
        int $agentId,
        ?int $runnerId,
    ): string {
        try {
            return $this->archive($base64, $mime, $prompt, $filename, $agentId, $runnerId, 0);
        } catch (MuseImageException $e) {
            // MuseImagePayloadException subclasses MuseImageException,
            // so this single arm covers both — Sonar `php:S5713`.
            $this->logger?->warning('muse-image.archive-fallback', [
                'exception'    => $e,
                'mime'         => $mime,
                'prompt_bytes' => strlen($prompt),
                'agent_id'     => $agentId,
            ]);
            return $this->dataUri($mime, $base64);
        } catch (Throwable $e) {
            $this->logger?->error('muse-image.archive-unexpected', [
                'exception'    => $e,
                'mime'         => $mime,
                'prompt_bytes' => strlen($prompt),
                'agent_id'     => $agentId,
            ]);
            return $this->dataUri($mime, $base64);
        }
    }

    /**
     * Ingest one block into the Media Archive when available; otherwise
     * short-circuit to a `data:` URI. The inner `Throwable` catch
     * converts ingest failures into a logged warning + data: URI
     * fallback, so callers can rely on this method never throwing.
     */
    private function archive(
        string $base64,
        string $mime,
        string $prompt,
        ?string $filename,
        int $agentId,
        ?int $runnerId,
        int $index,
    ): string {
        if (!$this->archive instanceof MediaArchiveService) {
            return $this->dataUri($mime, $base64);
        }
        $ext = $this->extensionForMime($mime);
        $archiveFilename = $filename !== null
            ? $filename . '.' . $ext
            : 'muse-image-' . ($index + 1) . '.' . $ext;
        try {
            $bytes = base64_decode($base64, true);
            if ($bytes === false) {
                throw new MuseImagePayloadException('Muse Image returned invalid base64 image data.');
            }
            $asset = $this->archive->ingest(new MediaIngestRequest(
                bytes: $bytes,
                mime: $mime,
                agentId: $agentId,
                userId: $runnerId,
                pluginSlug: 'muse',
                toolName: 'image',
                prompt: $prompt,
                filename: $archiveFilename,
            ));
            $url = (string) $asset->asset_url;
            return $url !== '' ? $url : $this->dataUri($mime, $base64);
        } catch (Throwable $e) {
            $this->logger?->warning('muse-image.archive-failed', [
                'exception'    => $e,
                'mime'         => $mime,
                'prompt_bytes' => strlen($prompt),
                'agent_id'     => $agentId,
            ]);
            return $this->dataUri($mime, $base64);
        }
    }

    private function dataUri(string $mime, string $base64): string
    {
        return 'data:' . $mime . self::DATA_URI_BASE64_PREFIX . $base64;
    }

    private function extensionForMime(string $mime): string
    {
        return match (strtolower($mime)) {
            self::MIME_FALLBACK => 'png',
            'image/jpeg'        => 'jpg',
            'image/webp'        => 'webp',
            default             => 'png',
        };
    }
}
