<?php

declare(strict_types=1);

namespace Clutch\Laravel\Console;

use Clutch\Laravel\Jobs\ReapAbandonedRuns;
use Illuminate\Console\Command;

class ReapCommand extends Command
{
    protected $signature = 'clutch:reap
        {--stale-after= : Seconds without a heartbeat before a run counts as abandoned}
        {--no-retry : Fail abandoned runs without queueing a new attempt}';

    protected $description = 'Detect runs whose worker disappeared and recover them';

    public function handle(): int
    {
        $job = new ReapAbandonedRuns(
            staleAfterSeconds: (int) ($this->option('stale-after')
                ?? config('clutch.recovery.stale_after_seconds', 1200)),
            retry: ! $this->option('no-retry'),
        );

        $this->laravel->call([$job, 'handle']);

        $this->components->info('Abandoned run sweep complete.');

        return self::SUCCESS;
    }
}
