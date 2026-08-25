<?php

declare(strict_types=1);

use Clutch\Laravel\Enums\RunStatus;
use Clutch\Laravel\Facades\Clutch;
use Clutch\Laravel\Models\Artifact;
use Clutch\Laravel\Models\Run;
use Clutch\Laravel\Models\Session;
use Clutch\Laravel\Runtime\ClutchResult;
use Clutch\Laravel\Tests\Fixtures\Agents\ResearchAgent;
use Clutch\Laravel\Tests\Fixtures\Workflows\CountingWorkflow;
use Clutch\Laravel\Tests\Fixtures\Workflows\FailingWorkflow;
use Clutch\Laravel\Tests\Fixtures\Workflows\ParallelWorkflow;
use Clutch\Laravel\Tests\Fixtures\Workflows\ResearchWorkflow;
use Clutch\Laravel\Workflows\Workflow;
use Clutch\Laravel\Workflows\WorkflowWorkspace;

beforeEach(function (): void {
    CountingWorkflow::reset();
    FailingWorkflow::$before = 0;

    $this->owner = $this->user();
});

it('pauses the workflow and parks the run without holding a worker', function (): void {
    $result = CountingWorkflow::start()->for($this->owner)->runNow(['name' => 'ada']);

    expect($result->isAwaitingApproval())->toBeTrue()
        ->and($result->run->status)->toBe(RunStatus::AwaitingApproval)
        ->and(CountingWorkflow::count('first'))->toBe(1)
        ->and(CountingWorkflow::count('second'))->toBe(0);
});

it('does not re-run a completed step when the workflow resumes', function (): void {
    $paused = CountingWorkflow::start()->for($this->owner)->runNow(['name' => 'ada']);

    $result = Workflow::resumeNow($paused->run->id, ['approved' => true]);

    expect($result->run->status)->toBe(RunStatus::Completed)
        // The whole contract in one assertion: the body re-entered from the
        // top, but the step it had already finished did not run again.
        ->and(CountingWorkflow::count('first'))->toBe(1)
        ->and(CountingWorkflow::count('second'))->toBe(1)
        ->and($result->run->structured_output)->toMatchArray([
            'first' => 'ADA',
            'second' => 'ADA:yes',
        ]);
});

it('carries a rejection into the workflow rather than killing the run', function (): void {
    $paused = CountingWorkflow::start()->for($this->owner)->runNow(['name' => 'ada']);

    $result = Workflow::resumeNow($paused->run->id, ['approved' => false, 'reason' => 'not yet']);

    expect($result->run->status)->toBe(RunStatus::Completed)
        ->and($result->run->structured_output['second'])->toBe('ADA:no');
});

it('records what the workflow emitted alongside the harness events', function (): void {
    $paused = CountingWorkflow::start()->for($this->owner)->runNow(['name' => 'ada']);

    $emitted = $paused->run->events()
        ->where('type', 'driver.event')
        ->get()
        ->pluck('payload.driver_type')
        ->all();

    expect($emitted)->toContain('workflow.greeted')
        ->and($emitted)->toContain('workflow.paused');

    $steps = $paused->run->events()
        ->where('type', 'step.completed')
        ->get()
        ->pluck('payload.step')
        ->all();

    expect($steps)->toContain('first');
});

it('names the step a workflow failed in', function (): void {
    $result = FailingWorkflow::start()->for($this->owner)->runNow([]);

    expect($result->run->status)->toBe(RunStatus::Failed)
        ->and($result->run->failure_message)->toContain('explodes')
        ->and(FailingWorkflow::$before)->toBe(1);
});

it('keeps finished steps when a failed workflow is retried', function (): void {
    $failed = FailingWorkflow::start()->for($this->owner)->runNow([]);

    expect(FailingWorkflow::$before)->toBe(1);

    $retried = app(\Clutch\Laravel\Runtime\RunCoordinator::class)->retryRun($failed->run);
    app(\Clutch\Laravel\Runtime\RunCoordinator::class)->executeRun($retried->id);

    // The step that succeeded is not paid for twice.
    expect(FailingWorkflow::$before)->toBe(1);
});

it('queues a workflow rather than running it in the request', function (): void {
    $run = CountingWorkflow::start()->for($this->owner)->dispatch(['name' => 'ada']);

    expect($run)->toBeInstanceOf(Run::class)
        ->and($run->input['payload'])->toBe(['name' => 'ada']);
});

it('prompts an agent from inside a workflow and keeps its own session', function (): void {
    Clutch::fake([ClutchResult::text('Three competitors, all mid-market.')]);

    $result = ResearchWorkflow::start()->for($this->owner)->runNow(['brief' => 'the CRM market']);

    expect($result->run->status)->toBe(RunStatus::Completed)
        ->and($result->run->structured_output['findings'])->toBe('Three competitors, all mid-market.');

    // The agent's own session is a separate record, tied back to the workflow
    // that caused it rather than floating unattached.
    $agentSession = Session::query()
        ->where('agent_class', ResearchAgent::class)
        ->firstOrFail();

    expect($agentSession->metadata['workflow'])->toBe(ResearchWorkflow::class)
        ->and($agentSession->metadata['workflow_run_id'])->toBe($result->run->id);
});

it('collects the files a workflow declared it produces', function (): void {
    Clutch::fake([ClutchResult::text('Mid-market, three of them.')]);

    $result = ResearchWorkflow::start()->for($this->owner)->runNow(['brief' => 'the CRM market']);

    $artifact = Artifact::query()->where('run_id', $result->run->id)->firstOrFail();

    expect($artifact->name)->toBe('reports/findings.md')
        ->and($artifact->contents())->toContain('Mid-market, three of them.');
});

it('stages inputs where the workflow can read them back', function (): void {
    Clutch::fake([ClutchResult::text('done')]);

    $result = ResearchWorkflow::start()->for($this->owner)->runNow(['brief' => 'the CRM market']);

    $workspace = new WorkflowWorkspace($result->run->session_id);

    expect($workspace->get('brief.txt'))->toBe('the CRM market');
});

it('runs independent steps together and returns them keyed', function (): void {
    $result = ParallelWorkflow::start()->for($this->owner)->runNow(['id' => 7]);

    expect($result->run->structured_output)->toBe([
        'account' => 'account:7',
        'usage' => 42,
    ]);
});

it('stops a cancelled workflow at a step boundary, keeping what finished', function (): void {
    $run = CountingWorkflow::start()->for($this->owner)->dispatch(['name' => 'ada']);

    // The dispatch above ran inline on the sync queue and parked for sign-off.
    app(\Clutch\Laravel\Runtime\RunCoordinator::class)->requestCancellation($run->refresh(), 'changed my mind');

    expect($run->refresh()->status)->toBeIn([RunStatus::Cancelling, RunStatus::Cancelled]);
});

it('generates a workflow that already uses a step', function (): void {
    $this->artisan('make:clutch-workflow', ['name' => 'OnboardCustomer'])->assertSuccessful();

    $path = app_path('Ai/Workflows/OnboardCustomer.php');

    expect(file_get_contents($path))
        ->toContain('extends Workflow')
        ->toContain("\$this->step('first'");
});

it('keeps step results across a killed worker and a reap', function (): void {
    $run = CountingWorkflow::start()->for($this->owner)->dispatch(['name' => 'ada']);

    // The run parked for sign-off. Simulate the worker vanishing mid-flight by
    // dropping it back to running with a stale heartbeat, which is exactly the
    // shape the reaper looks for.
    $run->refresh()->forceFill([
        'status' => RunStatus::Running,
        'heartbeat_at' => now()->subHour(),
    ])->save();

    app(\Clutch\Laravel\Jobs\ReapAbandonedRuns::class)->handle(
        app(\Clutch\Laravel\Runtime\RunCoordinator::class),
        app(\Clutch\Laravel\Leases\LeaseManager::class),
        app('log'),
    );

    // Whatever the reaper decided, the step that finished must still be on
    // record, or recovery would pay for it a second time.
    $checkpoint = \Clutch\Laravel\Models\Checkpoint::query()
        ->where('session_id', $run->session_id)
        ->latest('id')
        ->firstOrFail();

    expect($checkpoint->payload['steps'])->toHaveKey('first')
        ->and($checkpoint->payload['steps']['first']['value'])->toBe('ADA');
});
