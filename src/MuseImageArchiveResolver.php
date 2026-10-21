<?php

declare(strict_types=1);

namespace Spora\Plugins\Muse;

use Closure;
use Psr\Log\LoggerInterface;
use Spora\Tools\ValueObjects\ToolResult;

/**
 * Resolves Media Archive UUIDs and opaque `/api/v1/assets/<uuid>.<ext>`
 * URLs in the `input_images` slot into a form Meta can fetch.
 *
 * The LLM only ever holds the UUID: the bytes are read server-side,
 * base64-encoded, and written back into the same slot as a `data:` URI,
 * so they never enter the chat context.
 *
 * Three resolved forms (mirroring {@see \Spora\Services\MediaArchive\MediaAssetReader}):
 *   - `data_url` (DB BLOB) → `data:<mime>;base64,<payload>` (inlined)
 *   - `local` (disk)       → `data:<mime>;base64,<payload>` (loaded + encoded)
 *   - `external` (CDN)     → the original `source_url` (forwarded as-is;
 *                            Meta's `images/edits` endpoint fetches it server-side
 *                            only for http(s) URLs)
 *
 * A UUID that does not exist, or that the caller may not read, resolves
 * to a failed `ToolResult` with an LLM-actionable message: the LLM can
 * self-correct (generate a fresh image, paste a public URL) instead of
 * retrying the same UUID.
 *
 * The reader is taken as a closure rather than a concrete service class
 * because the host's `MediaAssetReader` is `final` and therefore cannot
 * be mocked from a plugin test.
 */
final class MuseImageArchiveResolver
{
    private const UUID_PATTERN = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';

    /**
     * Raw-byte ceiling for one reference image. Base64 is ~4/3 of its
     * source, so this bounds what gets inlined into the request to Meta;
     * above it the resolver fails with a hint to use a smaller image or a
     * public URL, rather than encoding a multi-megabyte blob.
     */
    private const MAX_RAW_BYTES = 20 * 1024 * 1024;

    /**
     * @param Closure(string $id, ?int $runnerUserId): ?array $reader
     *        Closure into the host's `MediaAssetReader::readAsset()` —
     *        see {@see \Spora\Services\MediaArchive\MediaAssetReader::readAsset()}
     *        for the return shape (`{status, bytes, mime}` or
     *        `{status, sourceUrl}` or `null`).
     *
     *        Core names that service's second argument `$userId`; the plugin
     *        calls the value it passes `$runnerUserId` because it is the user
     *        who triggered the task — the Media Archive ownership check asks
     *        "who is asking", not "whose key pays". The wire value is
     *        unchanged.
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
     * @param  int|null             $runnerUserId User the ownership check
     *                                          runs against, or null for the
     *                                          system-context bypass.
     * @return array{resolved: array<string, mixed>}|array{failed: ToolResult}
     */
    public function resolve(array $arguments, ?int $runnerUserId): array
    {
        if (!$this->hasResolvableImages($arguments)) {
            return ['resolved' => $arguments];
        }
        return $this->buildResolvedArguments($arguments, $runnerUserId);
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
    private function buildResolvedArguments(array $arguments, ?int $runnerUserId): array
    {
        $replaced = [];
        foreach ($arguments['input_images'] as $entry) {
            if (!is_string($entry)) {
                $replaced[] = $entry;
                continue;
            }
            $outcome = $this->resolveOne($entry, $runnerUserId);
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
    private function resolveOne(string $entry, ?int $runnerUserId): array
    {
        $uuid = $this->extractUuid($entry);
        if ($uuid === null) {
            return ['resolved' => $entry];
        }

        $result = ($this->reader)($uuid, $runnerUserId);
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
