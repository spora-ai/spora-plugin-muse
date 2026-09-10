<?php

declare(strict_types=1);

namespace Spora\Plugins\Skeleton;

use Spora\Plugins\AbstractPlugin;
use Spora\Plugins\Skeleton\Tools\EchoTool;

/**
 * Plugin entry point — extending {@see AbstractPlugin} (rather than directly
 * implementing {@see \Spora\Plugins\PluginInterface}) means we only have to
 * override the hooks we actually use. This skeleton ships tools, skills,
 * and agent templates as the worked example.
 *
 * The base class provides no-op defaults for autoload(), drivers(),
 * recipePaths(), schemaVersion(), migrationsPath(), apps(), routes(),
 * boot(), and register().
 */
final class SkeletonPlugin extends AbstractPlugin
{
    public function getName(): string
    {
        return 'Skeleton';
    }

    /** @return array<class-string<\Spora\Tools\ToolInterface>> */
    public function tools(): array
    {
        return [
            EchoTool::class,
        ];
    }

    /**
     * Skills this plugin ships. SkillScanner walks depth-1; remove this
     * method if your plugin ships none.
     *
     * @return string[]
     */
    public function skillPaths(): array
    {
        return [
            __DIR__ . '/../skills',
        ];
    }

    /**
     * Agent-template files this plugin ships. Remove if your plugin
     * ships none.
     *
     * @return string[]
     */
    public function agentTemplatePaths(): array
    {
        return [
            __DIR__ . '/../agent-templates',
        ];
    }
}
