<?php

declare(strict_types=1);

namespace Clutch\Laravel\Testing;

use Clutch\Laravel\Contracts\ClutchDriver;
use Clutch\Laravel\Data\ApprovalDecision;
use Clutch\Laravel\Data\Continuation;
use Clutch\Laravel\Data\StartSession;
use Clutch\Laravel\Data\TurnInput;
use Clutch\Laravel\Data\TurnResult;
use Clutch\Laravel\Enums\PermissionMode;
use Clutch\Laravel\Runtime\CancellationSignal;
use Clutch\Laravel\Runtime\Redactor;
use Clutch\Laravel\ValueObjects\TurnLimits;
use PHPUnit\Framework\Assert as PHPUnit;

/**
 * The shared suite every harness driver must pass.
 *
 * A driver author points this at their driver and gets the whole contract
 * checked: lifecycle, turn continuity, event ordering, tool pairing, approvals,
 * checkpoint round-tripping, cancellation, and secret exclusion. Capabilities a
 * driver does not declare are skipped rather than failed, so a deliberately
 * limited driver still passes — what it may not do is claim a capability it
 * does not have.
 *
 * ```php
 * DriverContractTests::for(new MyDriver)->run();
 * ```
 */
final class DriverContractTests
{
    /** @var array<int, string> */
    private array $passed = [];

    /** @var array<int, string> */
    private array $skipped = [];

    /**
     * @param  array<string, mixed>  $configuration
     */
    private function __construct(
        private readonly ClutchDriver $driver,
        private readonly string $sessionId = 'ses_contract',
        private readonly ?string $agentClass = null,
        private readonly string $runId = 'run_contract',
        private readonly array $configuration = [],
    ) {}

    /**
     * Point the suite at a driver.
     *
     * A driver that reads its own records needs the session and run ids to
     * match rows that exist, so both are settable. Everything else about the
     * contract is the same whichever driver is under test.
     *
     * @param  array<string, mixed>  $configuration  what start() will receive
     */
    public static function for(
        ClutchDriver $driver,
        ?string $agentClass = null,
        string $sessionId = 'ses_contract',
        string $runId = 'run_contract',
        array $configuration = [],
    ): self {
        return new self($driver, $sessionId, $agentClass, $runId, $configuration);
    }

    /**
     * Run every applicable check.
     *
     * @return array{passed: array<int, string>, skipped: array<int, string>}
     */
    public function run(): array
    {
        $this->declaresTruthfulCapabilities();
        $this->startsAndStopsASession();
        $this->processesANewTurn();
        $this->keepsSequentialTurnsInOneSession();
        $this->emitsEventsInOrder();
        $this->pairsToolCallsWithResults();
        $this->roundTripsACheckpoint();
        $this->keepsSecretsOutOfCheckpoints();
        $this->stopsWhenCancelled();
        $this->pausesForApprovalOrDeclaresItUnsupported();
        $this->resumesFromAnApprovalDecision();
        $this->returnsAConsistentTerminalResult();
        $this->slicesATurnOrDeclaresItUnsupported();

        return ['passed' => $this->passed, 'skipped' => $this->skipped];
    }

    // Checks -------------------------------------------------------------

    private function declaresTruthfulCapabilities(): void
    {
        $capabilities = $this->driver->capabilities();

        PHPUnit::assertNotSame('', $this->driver->name(), 'A driver must report a non-empty name.');

        // Capabilities must be stable: two reads cannot disagree, or the
        // harness's pre-flight check would be meaningless.
        PHPUnit::assertEquals(
            $capabilities->toArray(),
            $this->driver->capabilities()->toArray(),
            'A driver must report the same capabilities every time it is asked.',
        );

        foreach (['streaming', 'approvals', 'structured_output', 'session_resume'] as $capability) {
            PHPUnit::assertSame(
                $capabilities->toArray()[$capability],
                $capabilities->supports($capability),
                "Capability [{$capability}] must agree between supports() and toArray().",
            );
        }

        $this->pass('declares truthful capabilities');
    }

    private function startsAndStopsASession(): void
    {
        $session = $this->start();

        PHPUnit::assertSame($this->sessionId, $session->sessionId);
        PHPUnit::assertSame($this->driver->name(), $session->driver);

        $checkpoint = $this->driver->stop($session);

        PHPUnit::assertSame($this->driver->name(), $checkpoint->driver);
        PHPUnit::assertGreaterThan(0, $checkpoint->schemaVersion, 'A checkpoint must carry a schema version.');

        $this->driver->destroy($session);

        $this->pass('starts and stops a session');
    }

    private function processesANewTurn(): void
    {
        $sink = new RecordingSink;

        $result = $this->driver->runTurn($this->start(), $this->input('Say hello.'), $sink, CancellationSignal::never());

        PHPUnit::assertInstanceOf(TurnResult::class, $result);
        PHPUnit::assertContains($result->outcome, [
            TurnResult::COMPLETED,
            TurnResult::AWAITING_APPROVAL,
            TurnResult::CANCELLED,
            TurnResult::FAILED,
            TurnResult::BUDGET_EXCEEDED,
        ], 'A turn must report exactly one known outcome.');

        $this->pass('processes a new turn');
    }

    private function keepsSequentialTurnsInOneSession(): void
    {
        if (! $this->driver->capabilities()->sessionResume) {
            $this->skip('keeps sequential turns in one session (session_resume not declared)');

            return;
        }

        $session = $this->start();
        $sink = new RecordingSink;

        $first = $this->driver->runTurn($session, $this->input('First turn.'), $sink, CancellationSignal::never());

        PHPUnit::assertNotNull($first->session, 'A resumable driver must return its updated session handle.');

        $second = $this->driver->runTurn($first->session, $this->input('Second turn.'), $sink, CancellationSignal::never());

        PHPUnit::assertNotNull($second->session);
        PHPUnit::assertSame(
            $first->session->sessionId,
            $second->session->sessionId,
            'Both turns must belong to the same session.',
        );

        $this->pass('keeps sequential turns in one session');
    }

    private function emitsEventsInOrder(): void
    {
        $sink = new RecordingSink;

        $this->driver->runTurn($this->start(), $this->input('Emit some events.'), $sink, CancellationSignal::never());

        $types = array_column($sink->events, 'type');

        PHPUnit::assertNotEmpty($types, 'A turn must emit at least one event.');

        // Whatever a driver emits, a step must open before it closes.
        $started = array_search('step.started', $types, true);
        $completed = array_search('step.completed', $types, true);

        if ($started !== false && $completed !== false) {
            PHPUnit::assertLessThan($completed, $started, 'A step must start before it completes.');
        }

        $this->pass('emits events in order');
    }

    private function pairsToolCallsWithResults(): void
    {
        $sink = new RecordingSink;

        $this->driver->runTurn($this->start(), $this->input('Use a tool.'), $sink, CancellationSignal::never());

        $requested = $sink->payloadsOfType('tool.call.requested');
        $resolved = [
            ...$sink->payloadsOfType('tool.call.completed'),
            ...$sink->payloadsOfType('tool.call.failed'),
        ];

        foreach ($resolved as $payload) {
            PHPUnit::assertArrayHasKey(
                'tool_call_id',
                $payload,
                'Every tool result must carry the ID of the call it answers.',
            );
        }

        if ($requested !== []) {
            $requestedIds = array_column($requested, 'tool_call_id');

            foreach (array_column($resolved, 'tool_call_id') as $id) {
                PHPUnit::assertContains($id, $requestedIds, 'A tool result must answer a call that was requested.');
            }
        }

        $this->pass('pairs tool calls with results');
    }

    private function roundTripsACheckpoint(): void
    {
        $session = $this->start();

        $checkpoint = $this->driver->checkpoint($session);
        $restored = $this->driver->restore($checkpoint);

        PHPUnit::assertSame($session->sessionId, $restored->sessionId, 'A restored session must keep its identity.');
        PHPUnit::assertSame($session->driver, $restored->driver);
        PHPUnit::assertSame(
            $checkpoint->digest(),
            $this->driver->checkpoint($restored)->digest(),
            'Restoring and re-checkpointing must produce the same state.',
        );

        $this->pass('round-trips a checkpoint');
    }

    private function keepsSecretsOutOfCheckpoints(): void
    {
        $redactor = new Redactor([
            'authorization', 'api_key', 'token', 'password', 'secret', 'private_key',
        ]);

        $checkpoint = $this->driver->checkpoint($this->start());

        PHPUnit::assertFalse(
            $redactor->containsSensitiveKeys($checkpoint->payload),
            'A checkpoint must never contain a configured secret key.',
        );

        $this->pass('keeps secrets out of checkpoints');
    }

    private function stopsWhenCancelled(): void
    {
        $sink = new RecordingSink;

        $result = $this->driver->runTurn(
            $this->start(),
            $this->input('This should not run.'),
            $sink,
            CancellationSignal::cancelled('contract test'),
        );

        PHPUnit::assertTrue(
            $result->isCancelled(),
            'A driver handed an already-cancelled signal must not start new work.',
        );

        $this->pass('stops when cancelled');
    }

    private function pausesForApprovalOrDeclaresItUnsupported(): void
    {
        if (! $this->driver->capabilities()->approvals) {
            $this->skip('pauses for approval (approvals not declared)');

            return;
        }

        $this->pass('declares approval support');
    }

    private function resumesFromAnApprovalDecision(): void
    {
        if (! $this->driver->capabilities()->approvals) {
            $this->skip('resumes from an approval decision (approvals not declared)');

            return;
        }

        $sink = new RecordingSink;

        // A continuation only ever follows a turn. Driving one first is what
        // the coordinator does, and it is what gives a driver whatever state
        // its own resume depends on, such as a conversation.
        $first = $this->driver->runTurn(
            $this->start(),
            $this->input('Do something that might need approval.'),
            $sink,
            CancellationSignal::never(),
        );

        $result = $this->driver->continueTurn(
            $first->session ?? $this->start(),
            new Continuation($this->runId, [
                ApprovalDecision::approve('apr_1', 'call_1', 'some_tool'),
            ]),
            $sink,
            CancellationSignal::never(),
        );

        PHPUnit::assertInstanceOf(TurnResult::class, $result);

        // Whatever it decides, it must not invent a different session.
        if ($result->session instanceof \Clutch\Laravel\Data\DriverSession) {
            PHPUnit::assertSame(
                $this->sessionId,
                $result->session->sessionId,
                'A continuation must stay within the session it was given.',
            );
        }

        $this->pass('resumes from an approval decision');
    }

    private function returnsAConsistentTerminalResult(): void
    {
        $sink = new RecordingSink;

        $result = $this->driver->runTurn($this->start(), $this->input('Finish up.'), $sink, CancellationSignal::never());

        if ($result->isFailed()) {
            PHPUnit::assertNotNull($result->failure, 'A failed turn must describe its failure.');
        }

        if ($result->isAwaitingApproval()) {
            PHPUnit::assertNotEmpty(
                $result->pendingApprovals,
                'A turn awaiting approval must name what it is waiting on.',
            );
        }

        if ($result->isCompleted()) {
            PHPUnit::assertEmpty(
                $result->pendingApprovals,
                'A completed turn cannot still be awaiting approval.',
            );
        }

        $this->pass('returns a consistent terminal result');
    }

    /**
     * A driver claiming time slicing must actually hand a turn back.
     *
     * The check is the whole point of the capability flag: a driver that says
     * it can slice but runs to completion anyway would silently break every
     * caller sizing slices against a worker timeout.
     */
    private function slicesATurnOrDeclaresItUnsupported(): void
    {
        if (! $this->driver->capabilities()->timeSlicing) {
            $this->skip('slices a turn (time_slicing not declared)');

            return;
        }

        $sink = new RecordingSink;

        $result = $this->driver->runTurn(
            $this->start(),
            new TurnInput(
                runId: $this->runId,
                prompt: 'Do several things.',
                limits: TurnLimits::steps(1),
            ),
            $sink,
            CancellationSignal::never(),
        );

        PHPUnit::assertContains($result->outcome, [
            TurnResult::SUSPENDED,
            TurnResult::COMPLETED,
            TurnResult::AWAITING_APPROVAL,
        ], 'A sliced turn must suspend, or finish if there was less than a slice of work.');

        if ($result->isSuspended()) {
            PHPUnit::assertNotNull(
                $result->session,
                'A suspended turn must return the session handle the next slice resumes from.',
            );
        }

        $this->pass('slices a turn');
    }

    // Helpers ------------------------------------------------------------

    private function start(): \Clutch\Laravel\Data\DriverSession
    {
        return $this->driver->start(new StartSession(
            sessionId: $this->sessionId,
            agentClass: $this->agentClass,
            runtimeName: $this->agentClass === null ? $this->driver->name() : null,
            permissionMode: PermissionMode::ApproveSensitive,
            configuration: $this->configuration,
        ));
    }

    private function input(string $prompt): TurnInput
    {
        return new TurnInput(runId: $this->runId, prompt: $prompt);
    }

    private function pass(string $check): void
    {
        $this->passed[] = $check;
    }

    private function skip(string $check): void
    {
        $this->skipped[] = $check;
    }
}
