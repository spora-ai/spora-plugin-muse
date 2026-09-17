<?php

declare(strict_types=1);

namespace Spora\Plugins\Muse;

use Closure;
use Psr\Log\LoggerInterface;
use Spora\Tools\ValueObjects\ToolResult;

/**
 * Resolve Spora Media Archive UUIDs and opaque `/api/v1/assets/<uuid>.<ext>`
 * URLs in the `input_images` slot of the image-generation tool call into a
 * forwardable form before the request to Meta goes out.
 *
 * Pattern: the LLM discovers a Media Archive asset (e.g. via a previous
 * image generation in this session, or via `media:search`) and feeds the
 * UUID forward as `input_images: ["<uuid>"]`. The actual bytes never enter
 * the chat context — the resolver reads them server-side, base64-encodes
 * them, and replaces the slot with a `data:` URI in the same position.
 *
 * Three resolved forms (mirroring {@see \Spora\Services\MediaArchive\MediaAssetReader}):
 *   - `data_url` (DB BLOB) → `data:<mime>;base64,<payload>` (inlined)
 *   - `local` (disk)       → `data:<mime>;base64,<payload>` (loaded + encoded)
 *   - `external` (CDN)     → the original `source_url` (forwarded as-is;
 *                            Meta's `images/edits` endpoint fetches it server-side
 *                            only for http(s) URLs)
 *
 * For UUIDs that don't exist or aren't accessible to the caller, the
 * resolver returns a failed `ToolResult` with an LLM-actionable message
 * — surfacing the failure is the whole point: the LLM can self-correct
 * (generate a fresh image, paste a public URL, etc.) without retrying
 * with the same UUID.
 *
 * Size cap: 20 MB on the raw bytes (well under any plausible Meta image
 * size limit, and below the 50 MB data-URI ceiling the chat UI imposes
 * on inline blobs).
 *
 * The reader is taken as a closure rather than a concrete service class
 * so the plugin can ship without depending on `MediaAssetReader` directly
 * (the host's `MediaAssetReader` is `final` and therefore hard to mock
 * from a plugin test). The plugin's
 * {@see MusePlugin::onContainerBuilding()} wraps the
 * in-container reader in a one-line closure.
 */
final class MuseImageArchiveResolver
{
    private const UUID_PATTERN = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';

    /**
     * Maximum raw byte size that always fits in a `data:` URI plus
     * reasonable headroom for Meta's edit endpoint. Above this, the
     * resolver returns a failure with a downscaling hint instead of
     * trying to encode a multi-megabyte blob.
     */
    private const MAX_RAW_BYTES = 20 * 1024 * 1024;

    /**
     * @param Closure(string $id, ?int $userId): ?array $reader
     *        Closure into the host's `MediaAssetReader::readAsset()` —
     *        see {@see \Spora\Services\MediaArchive\MediaAssetReader::readAsset()}
     *        for the return shape (`{status, bytes, mime}` or
     *        `{status, sourceUrl}` or `null`).
     */
    public function __construct(
        private readonly Closure $reader,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    /**
     * Scan `input_images` in `$arguments` and replace any Media Archive
     * UUIDs with inline data URIs. Non-UUID entries (http/https URLs,
     * data URIs) pass through untouched.
     *
     * @param  array<string, mixed> $arguments
     * @return array{resolved: array<string, mixed>}|array{failed: ToolResult}
     */
    public function resolve(array $arguments, ?int $userId): array
    {
        if (!$this->hasResolvableImages($arguments)) {
            return ['resolved' => $arguments];
        }
        return $this->buildResolvedArguments($arguments, $userId);
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function hasResolvableImages(array $arguments): bool
    {
        return array_key_exists('input_images', $arguments)
            && is_array($arguments['input_images'])
            && $arguments['input_images'] !== [];
    }

    /**
     * @param  array<string, mixed> $arguments
     * @return array{resolved: array<string, mixed>}|array{failed: ToolResult}
     */
    private function buildResolvedArguments(array $arguments, ?int $userId): array
    {
        $replaced = [];
        foreach ($arguments['input_images'] as $entry) {
            if (!is_string($entry)) {
                $replaced[] = $entry;
                continue;
            }
            $outcome = $this->resolveOne($entry, $userId);
            if (isset($outcome['failed'])) {
                return ['failed' => $outcome['failed']];
            }
            $replaced[] = $outcome['resolved'];
        }

        $arguments['input_images'] = $replaced;
        return ['resolved' => $arguments];
    }

    /**
     * @return array{resolved: string}|array{failed: ToolResult}
     */
    private function resolveOne(string $entry, ?int $userId): array
    {
        $uuid = $this->extractUuid($entry);
        if ($uuid === null) {
            return ['resolved' => $entry];
        }

        $result = ($this->reader)($uuid, $userId);
        if ($result === null) {
            return ['failed' => $this->notFoundFailure($uuid)];
        }

        $this->logger?->debug('muse.media-archive-resolved', [
            'uuid'   => $uuid,
            'status' => $result['status'],
            'size'   => isset($result['bytes']) ? strlen($result['bytes']) : null,
        ]);

        return match ($result['status']) {
            'data_url', 'local' => $this->wrapAsDataUri($uuid, (string) $result['bytes'], (string) $result['mime']),
            'external'          => $this->forwardExternal($uuid, (string) $result['sourceUrl']),
            default             => ['failed' => $this->notFoundFailure($uuid)],
        };
    }

    /**
     * Match:
     *   - bare 36-char UUID (with optional `.ext`)
     *   - `/api/v1/assets/<uuid>` (with optional `.ext`)
     *
     * Anything else (http/https/data URI) returns null and falls through.
     */
    private function extractUuid(string $entry): ?string
    {
        if (preg_match('/^' . self::UUID_PATTERN . '(?:\.[A-Za-z0-9]+)?$/i', $entry) === 1) {
            return substr($entry, 0, 36);
        }
        if (preg_match('#^/api/v1/assets/(' . self::UUID_PATTERN . ')(?:\.[A-Za-z0-9]+)?$#i', $entry, $m) === 1) {
            return $m[1];
        }
        return null;
    }

    /**
     * @return array{resolved: string}|array{failed: ToolResult}
     */
    private function wrapAsDataUri(string $uuid, string $bytes, string $mime): array
    {
        $rawBytes = strlen($bytes);
        if ($rawBytes === 0) {
            return ['failed' => new ToolResult(false, sprintf(
                "Media asset %s has no readable bytes in the Spora Media Archive.",
                $uuid,
            ))];
        }
        if ($rawBytes > self::MAX_RAW_BYTES) {
            return ['failed' => new ToolResult(false, sprintf(
                "Media asset %s is %s MB, exceeds the %d MB cap. "
                    . 'Generate a smaller reference image, or paste a public URL.',
                $uuid,
                number_format($rawBytes / 1024 / 1024, 1),
                self::MAX_RAW_BYTES / 1024 / 1024,
            ))];
        }

        $dataUri = 'data:' . $mime . ';base64,' . base64_encode($bytes);
        return ['resolved' => $dataUri];
    }

    /**
     * @return array{resolved: string}
     */
    private function forwardExternal(string $uuid, string $sourceUrl): array
    {
        $this->logger?->debug('muse.media-archive-source-forwarded', [
            'uuid'       => $uuid,
            'source_url' => $sourceUrl,
        ]);
        return ['resolved' => $sourceUrl];
    }

    private function notFoundFailure(string $uuid): ToolResult
    {
        return new ToolResult(false, sprintf(
            "Media asset %s not found in the Spora Media Archive, or not accessible to this user. "
                . 'Verify the UUID, or paste a public URL for an externally-hosted image.',
            $uuid,
        ));
    }
}
