<?php

declare(strict_types=1);

namespace Spora\Plugins\Muse;

use RuntimeException;

/**
 * Thrown by {@see Tools\MuseImageGenerationTool} when
 * the base64-decoded image payload from Meta is malformed.
 */
final class MuseImagePayloadException extends RuntimeException {}
