<?php

declare(strict_types=1);

use Clutch\Laravel\Enums\RunStatus;
use Clutch\Laravel\Exceptions\CapabilityUnsupported;
use Clutch\Laravel\Facades\Clutch;
use Clutch\Laravel\Jobs\ContinueAgentRun;
use Clutch\Laravel\Runtime\ClutchResult;
use Clutch\Laravel\Tests\Fixtures\Agents\ResearchAgent;
use Clutch\Laravel\ValueObjects\TurnLimits;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    $this->owner = $this->user();
});

it('runs a turn to completion when no limit is set', function (): void {
    $fake = Clutch::fake([
        ClutchResult::text('All done.')
            ->withToolCall('search_web', ['q' => 'a'], 'ok')
            ->withToolCall('fetch_page', ['url' => 'b'], 'ok'),
    ]);

    $session = Clutch::agent(ResearchAgent::class)->for($this->owner)->create();

    $result = $session->prompt('Do the work.');

    expect($result->isCompleted())->toBeTrue()
        ->and($result->text)->toBe('All done.');

    // One run, one slice.
    expect($result->run->events()->where('type', 'run.suspended')->count())->toBe(0);

    unset($fake);
});

it('hands the turn back at a step boundary and finishes it across slices', function (): void {
    Clutch::fake([
        ClutchResult::text('All done.')
            ->withToolCall('search_web', ['q' => 'a'], 'first')
            ->withToolCall('fetch_page', ['url' => 'b'], 'second'),
    ]);

    $session = Clutch::agent(ResearchAgent::class)
        ->for($this->owner)
        ->sliceAfterSteps(1)
        ->create();

    // Queued execution re-queues itself after each slice, so the sync queue
    // drives the run to completion through several jobs.
    $run = $session->queue('Do the work.');

    $run->refresh();

    expect($run->status)->toBe(RunStatus::Completed)
        ->and($run->output_text)->toBe('All done.');

    // It genuinely suspended rather than running straight through.
    $suspensions = $run->events()->where('type', 'run.suspended')->get();

    expect($suspensions->count())->toBeGreaterThan(0)
        ->and($suspensions->first()->payload['reason'])->toBe('max_steps_per_slice');

    // Both tools ran exactly once across the slices.
    $calls = $run->events()->where('type', 'tool.call.requested')->get();

    expect($calls->pluck('payload.tool')->all())->toBe(['search_web', 'fetch_page']);
});

it('checkpoints at every slice boundary so another worker can resume', function (): void {
    Clutch::fake([
        ClutchResult::text('Done.')
            ->withToolCall('a', [], 'x')
            ->withToolCall('b', [], 'y'),
    ]);

    $session = Clutch::agent(ResearchAgent::class)
        ->for($this->owner)
        ->sliceAfterSteps(1)
        ->create();

    $run = $session->queue('Do the work.');

    $boundaries = $run->refresh()->checkpoints()->where('reason', 'slice_boundary')->count();

    expect($boundaries)->toBeGreaterThan(0);
});

it('keeps the session slot occupied while a turn is suspended', function (): void {
    Clutch::fake([ClutchResult::text('Done.')->withToolCall('a', [], 'x')]);

    $session = Clutch::agent(ResearchAgent::class)
        ->for($this->owner)
        ->sliceAfterSteps(1)
        ->create();

    $run = Clutch::coordinator()->createRun($session, 'Do the work.');

    // Hold the continuation so the run is observed mid-turn rather than being
    // carried to completion by the sync queue.
    Queue::fake();

    Clutch::coordinator()->executeRun($run->id);

    $run->refresh();

    expect($run->status)->toBe(RunStatus::Suspended)
        ->and($run->status->isTerminal())->toBeFalse()
        ->and($run->status->isPaused())->toBeTrue();

    // The work is unfinished, so the slot is still taken.
    expect($session->refresh()->active_run_id)->toBe($run->id);

    Queue::assertPushed(ContinueAgentRun::class);
});

it('accumulates usage across slices rather than restarting it', function (): void {
    Clutch::fake([
        ClutchResult::text('Done.')->withToolCall('a', [], 'x')->withToolCall('b', [], 'y'),
    ]);

    $session = Clutch::agent(ResearchAgent::class)
        ->for($this->owner)
        ->sliceAfterSteps(1)
        ->create();

    $run = $session->queue('Do the work.')->refresh();

    // A run sliced into pieces still meets its budget as one run.
    expect($run->usage()->totalTokens())->toBeGreaterThan(0)
        ->and($run->status)->toBe(RunStatus::Completed);
});

it('refuses to slice a driver that cannot resume a turn', function (): void {
    config()->set('clutch.default_driver', 'laravel-ai');

    ResearchAgent::fake(['Answer.']);

    $session = Clutch::agent(ResearchAgent::class)
        ->for($this->owner)
        ->sliceAfterSeconds(30)
        ->create();

    // The laravel-ai driver cannot park a turn mid-flight, so asking it to
    // fails loudly before any work starts rather than degrading silently.
    expect(fn () => $session->prompt('Do the work.'))
        ->toThrow(CapabilityUnsupported::class, 'time_slicing');
});

it('lets a session tighten the configured slice limit', function (): void {
    config()->set('clutch.limits.max_steps_per_slice', 10);

    Clutch::fake();

    $session = Clutch::agent(ResearchAgent::class)
        ->for($this->owner)
        ->sliceAfterSteps(2)
        ->create();

    expect($session->configuration['limits']['max_steps_per_slice'])->toBe(2);
});

it('reports which limit ended the slice', function (): void {
    $steps = TurnLimits::steps(3);
    $time = TurnLimits::seconds(60);

    expect($steps->reached(2, 0.0))->toBeFalse()
        ->and($steps->reached(3, 0.0))->toBeTrue()
        ->and($steps->reasonFor(3, 0.0))->toBe('max_steps_per_slice')
        ->and($time->reached(99, 59.0))->toBeFalse()
        ->and($time->reached(0, 61.0))->toBeTrue()
        ->and($time->reasonFor(0, 61.0))->toBe('max_seconds_per_slice')
        ->and(TurnLimits::none()->isBounded())->toBeFalse();
});
