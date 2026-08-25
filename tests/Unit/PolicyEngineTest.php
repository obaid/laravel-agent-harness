<?php

declare(strict_types=1);

use Clutch\Laravel\Contracts\SensitiveTool;
use Clutch\Laravel\Data\ToolInvocation;
use Clutch\Laravel\Enums\PermissionMode;
use Clutch\Laravel\Enums\ToolSensitivity;
use Clutch\Laravel\Policies\PolicyDecision;
use Clutch\Laravel\Policies\PolicyEngine;

function invocation(string $tool, PermissionMode $mode): ToolInvocation
{
    return new ToolInvocation(
        sessionId: 'ses_1',
        runId: 'run_1',
        toolCallId: 'call_1',
        toolName: $tool,
        permissionMode: $mode,
    );
}

it('treats an unclassified tool as sensitive', function (): void {
    $engine = new PolicyEngine;

    expect($engine->sensitivityOf('mystery_tool'))->toBe(ToolSensitivity::Sensitive);
});

it('lets read-only tools run under every mode except none', function (): void {
    $engine = new PolicyEngine(['search_web' => 'read_only']);

    foreach (PermissionMode::cases() as $mode) {
        expect($engine->decide(invocation('search_web', $mode))->isAllowed())->toBeTrue();
    }
});

it('asks for approval on sensitive tools in approve-sensitive mode', function (): void {
    $engine = new PolicyEngine([
        'draft_email' => 'reversible',
        'send_email' => 'irreversible',
    ]);

    expect($engine->decide(invocation('draft_email', PermissionMode::ApproveSensitive))->isAllowed())->toBeTrue()
        ->and($engine->decide(invocation('send_email', PermissionMode::ApproveSensitive))->requiresApproval())->toBeTrue();
});

it('asks for approval on every state-changing tool in approve-all mode', function (): void {
    $engine = new PolicyEngine(['draft_email' => 'reversible']);

    expect($engine->decide(invocation('draft_email', PermissionMode::ApproveAll))->requiresApproval())->toBeTrue();
});

it('denies un-allowlisted tools outright under deny-by-default', function (): void {
    $engine = new PolicyEngine(['send_email' => 'irreversible'], alwaysAllow: ['search_web']);

    expect($engine->decide(invocation('send_email', PermissionMode::DenyByDefault))->isDenied())->toBeTrue()
        ->and($engine->decide(invocation('search_web', PermissionMode::DenyByDefault))->isAllowed())->toBeTrue();
});

it('runs everything without harness approval in allow-all mode', function (): void {
    $engine = new PolicyEngine(['send_email' => 'irreversible']);

    expect($engine->decide(invocation('send_email', PermissionMode::AllowAll))->isAllowed())->toBeTrue();
});

it('prefers a tool\'s own classification over configuration', function (): void {
    $engine = new PolicyEngine(['thing' => 'read_only']);

    $tool = new class implements SensitiveTool
    {
        public function sensitivity(): ToolSensitivity
        {
            return ToolSensitivity::Irreversible;
        }
    };

    expect($engine->sensitivityOf('thing', $tool))->toBe(ToolSensitivity::Irreversible);
});

it('lets the most restrictive application rule win', function (): void {
    $engine = (new PolicyEngine(['search_web' => 'read_only']))
        ->extend(fn (ToolInvocation $i): ?PolicyDecision => $i->toolName === 'search_web'
            ? PolicyDecision::deny('Search is disabled for this tenant.')
            : null);

    $decision = $engine->decide(invocation('search_web', PermissionMode::AllowAll));

    expect($decision->isDenied())->toBeTrue()
        ->and($decision->reason)->toBe('Search is disabled for this tenant.');
});

it('ignores a rule that defers', function (): void {
    $engine = (new PolicyEngine(['search_web' => 'read_only']))
        ->extend(fn (): ?PolicyDecision => null);

    expect($engine->decide(invocation('search_web', PermissionMode::ApproveSensitive))->isAllowed())->toBeTrue();
});

it('classifies a tool at runtime', function (): void {
    $engine = (new PolicyEngine)->classify('search_web', ToolSensitivity::ReadOnly);

    expect($engine->decide(invocation('search_web', PermissionMode::ApproveSensitive))->isAllowed())->toBeTrue();
});
