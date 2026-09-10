<?php

declare(strict_types=1);

namespace Spora\Plugins\Skeleton\Tools;

use Spora\Tools\AbstractTool;
use Spora\Tools\Attributes\Tool;
use Spora\Tools\Attributes\ToolParameter;
use Spora\Tools\ValueObjects\ToolResult;

/**
 * Trivial example tool: returns whatever message the agent passes in.
 *
 * Use it as a copy-and-rename starting point for real tools. The two
 * attributes below are how Spora discovers your tool at boot and how the
 * admin UI renders its parameter form.
 */
#[Tool(
    name: 'echo',
    description: 'Returns the supplied message unchanged. A no-op example tool to bootstrap plugin development.',
)]
#[ToolParameter(
    name: 'message',
    type: 'string',
    description: 'The text the tool will return verbatim.',
    required: true,
)]
final class EchoTool extends AbstractTool
{
    /**
     * @param array<string, mixed> $arguments
     */
    public function execute(
        array $arguments,
        int $agentId,
        ?int $userId = null,
        ?int $taskId = null,
    ): ToolResult {
        $message = (string) ($arguments['message'] ?? '');

        return ToolResult::ok(
            content: "Echoed: {$message}",
            data: ['echoed' => $message],
        );
    }

    /**
     * Short human-readable summary of what an invocation would do, used by
     * the admin UI's agent-trace view and by orchestration logs.
     *
     * @param array<string, mixed> $arguments
     */
    public function describeAction(array $arguments): string
    {
        $preview = mb_substr(trim((string) ($arguments['message'] ?? '')), 0, 80);
        return "Echo: '{$preview}'";
    }
}
