<?php

declare(strict_types=1);

namespace Spora\Plugins\Muse;

use Psr\Log\LoggerInterface;
use Spora\Events\ContainerBuildingEvent;
use Spora\Plugins\AbstractPlugin;
use Spora\Plugins\Muse\Tools\MuseImageGenerationTool;
use Spora\Services\MediaArchive\MediaArchiveService;
use Spora\Speech\SpeechToTextProviderInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Meta Muse vendor home — speech-to-text + image generation today.
 *
 * Contributes one STT provider ({@see MuseTranscribeProvider}, exposed
 * via the {@see speechToTextProviders()} data hook) and one image
 * generation tool ({@see MuseImageGenerationTool}, exposed via
 * {@see tools()}). The plugin entry point itself just declares the
 * contributions — all DI wiring lives on the provider / tool classes,
 * except for the {@see onContainerBuilding()} event below, which
 * PHP-DI needs help with for nullable ctor params + setter injection.
 *
 * Future Meta capabilities (Muse Spark text chat, Muse Glimmer TTS,
 * Muse Video) join under the same plugin home.
 */
final class MusePlugin extends AbstractPlugin implements EventSubscriberInterface
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

    /**
     * Plugin-shipped skills live as siblings under `<plugin>/skills/<slug>/SKILL.md`.
     * Today: `muse-image` (canonical recipe for the image-generation tool).
     *
     * `is_dir` guard keeps the override side-effect-free when the directory
     * is absent (e.g. checkout without the `skills/` subtree).
     *
     * @return string[]
     */
    public function skillPaths(): array
    {
        $path = \dirname(__DIR__) . '/skills';
        return is_dir($path) ? [$path] : [];
    }

    /**
     * Agent-template files for the Muse plugin. The scanner reads depth-0
     * `.json` / `.yaml` / `.yml` files. Today: `muse-image-expert.json`
     * (a single-purpose image-generation agent that loads the `muse-image`
     * skill as its canonical recipe).
     *
     * @return string[]
     */
    public function agentTemplatePaths(): array
    {
        return [
            \dirname(__DIR__) . '/agent-templates',
        ];
    }

    /**
     * Subscribe to the framework's boot-time events.
     *
     * - {@see ContainerBuildingEvent} fires once per process, before the
     *   DI container is built. {@see self::onContainerBuilding()} adds
     *   the {@see MediaArchiveService} + {@see LoggerInterface} bindings
     *   the tool needs via setter injection — without this, the tool
     *   silently falls back to returning `data:` URIs and the chat UI
     *   sanitizes them to `[data-omitted]`.
     *
     * @return array<class-string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            ContainerBuildingEvent::class => 'onContainerBuilding',
        ];
    }

    /**
     * PHP-DI quirk: nullable ctor params with `= null` defaults are
     * short-circuited to null by DefaultValueResolver before the type-hint
     * resolver runs, so the tool's optional `?LoggerInterface $logger`
     * ctor param never gets autowired. `MediaArchiveService` is *not*
     * a ctor param at all — it's a setter, which PHP-DI only calls if
     * told to. Both need explicit `\DI\autowire()->method(...)` wiring.
     *
     * Without this, the tool renders inline images as raw `data:` URIs,
     * the chat UI sanitizer truncates them to `[data-omitted]`, and the
     * user sees a broken placeholder. With it, images land in the Media
     * Archive and render as `/api/v1/assets/<token>.<ext>`.
     */
    public function onContainerBuilding(ContainerBuildingEvent $event): void
    {
        $builder        = $event->builder();
        $archiveService = \DI\get(MediaArchiveService::class);
        $logger         = \DI\get(LoggerInterface::class);

        $builder->addDefinitions([
            MuseImageGenerationTool::class => \DI\autowire()
                ->method('setMediaArchive', $archiveService)
                ->method('setLogger', $logger),
        ]);
    }
}
