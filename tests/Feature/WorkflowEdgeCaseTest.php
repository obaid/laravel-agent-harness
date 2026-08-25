<?php

declare(strict_types=1);

use Clutch\Laravel\Enums\RunStatus;
use Clutch\Laravel\Tests\Fixtures\Workflows\EdgeCaseWorkflow;
use Clutch\Laravel\Workflows\Workflow;

beforeEach(function (): void {
    EdgeCaseWorkflow::reset();
    $this->owner = $this->user();
});

it('handles a workflow that pauses more than once', function (): void {
    $run = EdgeCaseWorkflow::start()->for($this->owner)->dispatch(['mode' => 'two-pauses']);

    expect($run->refresh()->status)->toBe(RunStatus::AwaitingApproval)
        ->and(EdgeCaseWorkflow::$ran)->toBe([]);

    Workflow::resume($run->id, ['approved' => true]);

    // The second gate stops it again rather than running to the end.
    expect($run->refresh()->status)->toBe(RunStatus::AwaitingApproval)
        ->and(EdgeCaseWorkflow::$ran)->toBe(['middle']);

    Workflow::resume($run->id, ['approved' => true]);

    expect($run->refresh()->status)->toBe(RunStatus::Completed)
        ->and($run->structured_output)->toMatchArray([
            'first' => true,
            'second' => true,
            'middle' => 'did the middle',
        ])
        // The step between the two gates ran once, not once per resume.
        ->and(EdgeCaseWorkflow::$ran)->toBe(['middle']);
});

it('handles a pause inside a loop, one item at a time', function (): void {
    $run = EdgeCaseWorkflow::start()->for($this->owner)
        ->dispatch(['mode' => 'loop', 'items' => ['a', 'b']]);

    Workflow::resume($run->id, ['approved' => true]);
    expect(EdgeCaseWorkflow::$ran)->toBe(['a']);

    Workflow::resume($run->id, ['approved' => true]);

    expect($run->refresh()->status)->toBe(RunStatus::Completed)
        ->and($run->structured_output['done'])->toBe(['did a', 'did b'])
        ->and(EdgeCaseWorkflow::$ran)->toBe(['a', 'b']);
});

it('treats a step that returned null as done rather than never run', function (): void {
    $run = EdgeCaseWorkflow::start()->for($this->owner)->dispatch(['mode' => 'null-step']);

    expect(EdgeCaseWorkflow::$ran)->toBe(['null-step']);

    Workflow::resume($run->id, ['approved' => true]);

    expect($run->refresh()->status)->toBe(RunStatus::Completed)
        ->and($run->structured_output['nothing'])->toBeNull()
        // The proof: re-entry did not run it a second time.
        ->and($run->structured_output['ran'])->toBe(1);
});
