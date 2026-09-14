<?php

declare(strict_types=1);

namespace Spora\Plugins\Muse;

use RuntimeException;
use Throwable;

/**
 * Thrown by {@see MuseImageHttpClient} on every upstream failure
 * (transport timeout, non-2xx HTTP, non-JSON body). Carries the
 * current `http_timeout_seconds` so the error message can point the
 * LLM at the operator-configurable setting.
 */
final class MuseImageException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $timeoutSeconds,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
