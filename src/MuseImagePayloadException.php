<?php

declare(strict_types=1);

namespace Spora\Plugins\Muse;

use Throwable;

/**
 * Thrown by {@see Tools\MuseImageGenerationTool} when
 * the base64-decoded image payload from Meta is malformed.
 *
 * Subclasses {@see MuseImageException} (not {@see RuntimeException}
 * directly) so {@see Tools\MuseImageGenerationTool} can catch a single
 * `MuseImageException` and surface a failed {@see \Spora\Tools\ValueObjects\ToolResult}
 * instead of letting the exception escape {@see \Spora\Tools\ToolInterface::execute()},
 * which the ToolInterface contract forbids.
 */
final class MuseImagePayloadException extends MuseImageException
{
    public function __construct(
        string $message,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, self::DEFAULT_TIMEOUT_SECONDS, $previous);
    }
}
