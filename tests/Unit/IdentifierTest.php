<?php

declare(strict_types=1);

use AgentHarness\Laravel\Support\Id;

it('generates a prefixed identifier for each record type', function (): void {
    expect(Id::session())->toStartWith('ses_')
        ->and(Id::run())->toStartWith('run_')
        ->and(Id::event())->toStartWith('evt_')
        ->and(Id::approval())->toStartWith('apr_')
        ->and(Id::checkpoint())->toStartWith('chk_')
        ->and(Id::artifact())->toStartWith('art_')
        ->and(Id::toolCall())->toStartWith('tcl_');
});

it('generates sortable identifiers', function (): void {
    $ids = [];

    for ($i = 0; $i < 20; $i++) {
        $ids[] = Id::run();
        usleep(1200);
    }

    $sorted = $ids;
    sort($sorted);

    expect($sorted)->toBe($ids);
});

it('validates identifiers, optionally against a prefix', function (): void {
    $run = Id::run();

    expect(Id::isValid($run))->toBeTrue()
        ->and(Id::isValid($run, Id::RUN))->toBeTrue()
        ->and(Id::isValid($run, Id::SESSION))->toBeFalse()
        ->and(Id::isValid('not-an-id'))->toBeFalse()
        ->and(Id::isValid('run_nope'))->toBeFalse()
        ->and(Id::isValid('xyz_01hq0000000000000000000000'))->toBeFalse();
});

it('reads back an identifier prefix', function (): void {
    expect(Id::prefix(Id::approval()))->toBe('apr')
        ->and(Id::prefix('garbage'))->toBeNull();
});

it('refuses an unknown prefix', function (): void {
    expect(fn (): string => Id::make('xyz'))->toThrow(InvalidArgumentException::class);
});

it('does not produce guessable sequential identifiers', function (): void {
    $a = Id::session();
    $b = Id::session();

    expect($a)->not->toBe($b)
        ->and(strlen($a))->toBe(30);
});
