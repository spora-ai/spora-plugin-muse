<?php

declare(strict_types=1);

namespace Spora\Plugins\Muse;

use Psr\Log\LoggerInterface;
use Spora\Events\ContainerBuildingEvent;
use Spora\Plugins\AbstractPlugin;
use Spora\Plugins\Muse\Tools\MuseImageGenerationTool;
use Spora\Services\MediaArchive\MediaArchiveService;
use Spora\Services\MediaArchive\MediaAssetReader;
use Spora\Speech\SpeechToTextProviderInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * The entry point only declares contributions — the DI wiring for the
 * provider and the tool lives on those classes. The exception is
 * {@see onContainerBuilding()}, which PHP-DI cannot infer.
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
     *
     * The `is_dir` guard keeps the hook side-effect-free when the subtree
     * is absent (e.g. a checkout without `skills/`).
     *
     * @return string[]
     */
    public function skillPaths(): array
    {
        $path = \dirname(__DIR__) . '/skills';
        return is_dir($path) ? [$path] : [];
    }

    /**
     * Agent-template files for the Muse plugin. Core's scanner reads
     * depth-0 `.json` / `.yaml` / `.yml` files.
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
     * {@see ContainerBuildingEvent} fires once per process, before the DI
     * container is built. {@see self::onContainerBuilding()} adds the
     * {@see MediaArchiveService} + {@see LoggerInterface} bindings the
     * tool needs via setter injection — without them the tool falls back
     * to `data:` URIs, which core's `ScrubDataUrls` replaces with the
     * literal `[data-omitted]` before the result is stored.
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
     * PHP-DI quirk: a nullable ctor param with a `= null` default is
     * filled in by `DefaultValueResolver` before the type-hint resolver
     * runs, so the tool's optional `?LoggerInterface $logger` ctor param
     * never gets autowired. `MediaArchiveService` and
     * `MuseImageArchiveResolver` are not ctor params at all — they're
     * setters, which PHP-DI only calls if told to. All three need
     * explicit `\DI\autowire()->method(...)` wiring.
     *
     * Without it the tool returns `data:` URIs, which core's
     * `ScrubDataUrls` replaces with the literal `[data-omitted]` before
     * the result is stored — the operator sees a broken image. With it,
     * images land in the Media Archive and render as
     * `/api/v1/assets/<uuid>.<ext>`.
     *
     * The resolver takes a closure into the host's `MediaAssetReader`
     * rather than the type itself: the core class is `final`, so a plugin
     * test cannot mock it — the closure is the seam.
     */
    public function onContainerBuilding(ContainerBuildingEvent $event): void
    {
        $builder        = $event->builder();
        $archiveService = \DI\get(MediaArchiveService::class);
        $logger         = \DI\get(LoggerInterface::class);

        $resolver = \DI\factory(static function (
            MediaAssetReader $reader,
            ?LoggerInterface $logger,
        ): MuseImageArchiveResolver {
            return new MuseImageArchiveResolver(
                static fn(string $id, ?int $runnerUserId): ?array => $reader->readAsset($id, $runnerUserId),
                $logger,
            );
        });

        $builder->addDefinitions([
            MuseImageArchiveResolver::class => $resolver,

            MuseImageGenerationTool::class => \DI\autowire()
                ->method('setMediaArchive', $archiveService)
                ->method('setImageArchiveResolver', \DI\get(MuseImageArchiveResolver::class))
                ->method('setLogger', $logger),
        ]);
    }

    /**
     * The plugin's `locales/` directory — one `<lang>.json` per language.
     *
     * @return string[]
     */
    public function translationsPaths(): array
    {
        return [
            __DIR__ . '/../locales',
        ];
    }
}
