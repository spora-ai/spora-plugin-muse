<?php

declare(strict_types=1);

use Spora\Plugins\Skeleton\Tools\EchoTool;

it('returns the message unchanged', function (): void {
    $tool = new EchoTool();

    $result = $tool->execute(['message' => 'hello'], agentId: 1);

    expect($result->success)->toBeTrue();
    expect($result->data['echoed'])->toBe('hello');
});

it('treats a missing message as the empty string', function (): void {
    $tool = new EchoTool();

    $result = $tool->execute([], agentId: 1);

    expect($result->success)->toBeTrue();
    expect($result->data['echoed'])->toBe('');
});
