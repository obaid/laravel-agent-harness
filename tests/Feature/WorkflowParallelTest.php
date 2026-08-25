<?php

declare(strict_types=1);

use Clutch\Laravel\Enums\RunStatus;
use Clutch\Laravel\Runtime\RunCoordinator;
use Clutch\Laravel\Tests\Fixtures\Workflows\PartialFailureWorkflow;

beforeEach(function (): void {
    PartialFailureWorkflow::reset();
    $this->owner = $this->user();

    // Sequential, so the failure is deterministic rather than racing.
    config()->set('clutch.workflows.concurrent_steps', false);
});

it('re-runs only the concurrent step that was missing', function (): void {
    $failed = PartialFailureWorkflow::start()->for($this->owner)->runNow([]);

    expect($failed->run->status)->toBe(RunStatus::Failed)
        ->and(PartialFailureWorkflow::$ran)->toBe(['good', 'flaky']);

    PartialFailureWorkflow::$failSecond = false;
    PartialFailureWorkflow::$ran = [];

    $coordinator = app(RunCoordinator::class);
    $retried = $coordinator->retryRun($failed->run);
    $coordinator->executeRun($retried->id);

    expect($retried->refresh()->status)->toBe(RunStatus::Completed)
        ->and($retried->structured_output)->toBe([
            'good' => 'fetched',
            'flaky' => 'fetched late',
        ])
        // The one that succeeded is not paid for twice.
        ->and(PartialFailureWorkflow::$ran)->toBe(['flaky']);
});
