<?php

declare(strict_types=1);

namespace Spora\Plugins\Muse;

use Spora\Plugins\AbstractPlugin;
use Spora\Plugins\Muse\Tools\MuseImageGenerationTool;
use Spora\Speech\SpeechToTextProviderInterface;

/**
 * Meta Muse vendor home — speech-to-text + image generation today.
 *
 * Contributes one STT provider ({@see MuseTranscribeProvider}, exposed
 * via the {@see speechToTextProviders()} data hook) and one image
 * generation tool ({@see MuseImageGenerationTool}, exposed via
 * {@see tools()}). The plugin entry point itself just declares the
 * contributions — all DI wiring lives on the provider / tool classes.
 *
 * Future Meta capabilities (Muse Spark text chat, Muse Glimmer TTS,
 * Muse Video) join under the same plugin home.
 */
final class MusePlugin extends AbstractPlugin
{
    public function getName(): string
    {
        return 'Meta Muse';
    }

    /** @return list<class-string<\Spora\Tools\ToolInterface>> */
    public function tools(): array
    {
        return [MuseImageGenerationTool::class];
    }

    /** @return list<class-string<SpeechToTextProviderInterface>> */
    public function speechToTextProviders(): array
    {
        return [MuseTranscribeProvider::class];
    }
}
