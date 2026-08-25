<?php

declare(strict_types=1);

use Clutch\Laravel\Drivers\FakeDriver;
use Clutch\Laravel\Runtime\ClutchResult;
use Clutch\Laravel\Testing\DriverContractTests;

it('passes the shared driver contract suite', function (): void {
    $result = DriverContractTests::for(new FakeDriver([
        ClutchResult::text('Hello there.')->withToolCall('search_web', ['q' => 'laravel'], 'results'),
    ]))->run();

    expect($result['passed'])->toContain('starts and stops a session')
        ->toContain('processes a new turn')
        ->toContain('keeps sequential turns in one session')
        ->toContain('emits events in order')
        ->toContain('pairs tool calls with results')
        ->toContain('round-trips a checkpoint')
        ->toContain('keeps secrets out of checkpoints')
        ->toContain('stops when cancelled')
        ->toContain('returns a consistent terminal result');

    expect($result['skipped'])->toBeEmpty();
});

it('skips checks a driver does not claim to support', function (): void {
    $limited = new class extends FakeDriver
    {
        public function capabilities(): Clutch\Laravel\ValueObjects\DriverCapabilities
        {
            return new Clutch\Laravel\ValueObjects\DriverCapabilities(
                streaming: false,
                approvals: false,
                sessionResume: false,
            );
        }
    };

    $result = DriverContractTests::for($limited)->run();

    expect($result['skipped'])
        ->toContain('keeps sequential turns in one session (session_resume not declared)')
        ->toContain('resumes from an approval decision (approvals not declared)');

    // A deliberately limited driver still passes the contract.
    expect($result['passed'])->toContain('starts and stops a session');
});
