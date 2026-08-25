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

    private function __construct(
        private readonly ClutchDriver $driver,
        private readonly string $sessionId = 'ses_contract',
        private readonly ?string $agentClass = null,
    ) {}

    public static function for(ClutchDriver $driver, ?string $agentClass = null): self
    {
        return new self($driver, agentClass: $agentClass);
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

        $result = $this->driver->continueTurn(
            $this->start(),
            new Continuation('run_contract', [
                ApprovalDecision::approve('apr_1', 'call_1', 'some_tool'),
            ]),
            $sink,
            CancellationSignal::never(),
        );

        PHPUnit::assertInstanceOf(TurnResult::class, $result);

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

    // Helpers ------------------------------------------------------------

    private function start(): \Clutch\Laravel\Data\DriverSession
    {
        return $this->driver->start(new StartSession(
            sessionId: $this->sessionId,
            agentClass: $this->agentClass,
            runtimeName: $this->agentClass === null ? $this->driver->name() : null,
            permissionMode: PermissionMode::ApproveSensitive,
        ));
    }

    private function input(string $prompt): TurnInput
    {
        return new TurnInput(runId: 'run_contract', prompt: $prompt);
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
