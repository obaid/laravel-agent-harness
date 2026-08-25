<?php

declare(strict_types=1);

use Clutch\Laravel\Enums\EventType;
use Clutch\Laravel\Facades\Clutch;
use Clutch\Laravel\Models\RunEvent;
use Clutch\Laravel\Runtime\ClutchResult;
use Clutch\Laravel\Streaming\StreamedRun;
use Clutch\Laravel\Streaming\VercelDataProtocol;
use Clutch\Laravel\Tests\Fixtures\Agents\ResearchAgent;

beforeEach(function (): void {
    $this->owner = $this->user();
    $this->clutch = Clutch::fake([ClutchResult::text('The report is ready to review.')]);
    $this->session = Clutch::agent(ResearchAgent::class)->for($this->owner)->create();
});

it('yields events as they are recorded rather than after the run', function (): void {
    $stream = $this->session->stream('Create the report.');

    expect($stream)->toBeInstanceOf(StreamedRun::class);

    $seen = [];
    $persistedWhenFirstSeen = null;

    foreach ($stream as $event) {
        $seen[] = $event->type->value;

        // Every event handed to a consumer is already durable, so a client
        // reconnecting immediately after can replay exactly what it saw.
        $persistedWhenFirstSeen ??= RunEvent::query()->whereKey($event->id)->exists();
    }

    expect($persistedWhenFirstSeen)->toBeTrue()
        ->and($seen)->toContain('run.started')
        ->and($seen)->toContain('text.delta')
        ->and($seen)->toContain('run.completed');
});

it('returns the terminal result after the stream drains', function (): void {
    $result = $this->session->stream('Create the report.')->wait();

    expect($result->isCompleted())->toBeTrue()
        ->and($result->text)->toBe('The report is ready to review.');
});

it('produces the same stored history for streamed and queued runs', function (): void {
    $streamed = $this->session->stream('Create the report.')->wait();

    $this->clutch->script([ClutchResult::text('The report is ready to review.')]);

    $second = Clutch::agent(ResearchAgent::class)->for($this->owner)->create();
    $queued = $second->queue('Create the report.');

    // A queued run additionally records run.queued; everything the driver
    // produced is otherwise identical, which is what makes a reconnecting
    // client's view independent of how the run was started.
    $withoutQueueBookkeeping = fn ($run) => $run->events()
        ->pluck('type')
        ->map->value
        ->reject(fn (string $type): bool => $type === 'run.queued')
        ->values()
        ->all();

    expect($withoutQueueBookkeeping($queued->refresh()))
        ->toBe($withoutQueueBookkeeping($streamed->run));
});

it('replays from a cursor and continues in order', function (): void {
    $result = $this->session->stream('Create the report.')->wait();

    $all = $result->run->events()->get();

    // A client that disconnected after event 3 asks for everything since.
    $resumed = $result->run->eventsAfter(3);

    expect($resumed->first()->sequence)->toBe(4)
        ->and($resumed->pluck('sequence')->all())->toBe(range(4, $all->count()))
        ->and($resumed->last()->type->isTerminal())->toBeTrue();
});

it('assigns every event a unique, monotonic sequence within its run', function (): void {
    $result = $this->session->stream('Create the report.')->wait();

    $sequences = $result->run->events()->pluck('sequence');

    expect($sequences->duplicates())->toBeEmpty()
        ->and($sequences->all())->toBe($sequences->sort()->values()->all());
});

it('maps harness events onto the Vercel data protocol', function (): void {
    $protocol = new VercelDataProtocol;

    $delta = new RunEvent([
        'run_id' => 'run_x',
        'type' => EventType::TextDelta,
        'payload' => ['message_id' => 'msg_1', 'delta' => 'Hello'],
    ]);

    expect($protocol->map($delta))->toBe([
        ['type' => 'text-delta', 'id' => 'msg_1', 'delta' => 'Hello'],
    ]);

    $toolCall = new RunEvent([
        'run_id' => 'run_x',
        'type' => EventType::ToolCallRequested,
        'payload' => ['tool_call_id' => 'call_1', 'tool' => 'search', 'arguments' => ['q' => 'x']],
    ]);

    expect($protocol->map($toolCall))->toBe([[
        'type' => 'tool-input-available',
        'toolCallId' => 'call_1',
        'toolName' => 'search',
        'input' => ['q' => 'x'],
    ]]);

    $approval = new RunEvent([
        'run_id' => 'run_x',
        'type' => EventType::ApprovalRequested,
        'payload' => ['approval_id' => 'apr_1', 'tool' => 'publish', 'tool_call_id' => 'call_9'],
    ]);

    expect($protocol->map($approval)[0]['type'])->toBe('data-approval-request');
});

it('closes the stream with a terminal event', function (): void {
    $result = $this->session->stream('Create the report.')->wait();

    $last = $result->run->events()->get()->last();

    expect($last->isTerminal())->toBeTrue()
        ->and($last->type)->toBe(EventType::RunCompleted);
});

it('lets a caller observe events without consuming the iterator', function (): void {
    $seen = [];

    $this->session
        ->stream('Create the report.')
        ->each(function (RunEvent $event) use (&$seen): void {
            $seen[] = $event->type->value;
        })
        ->wait();

    expect($seen)->toContain('run.completed');
});
