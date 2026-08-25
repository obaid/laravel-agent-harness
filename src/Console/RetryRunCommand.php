<?php

declare(strict_types=1);

namespace Clutch\Laravel\Console;

use Clutch\Laravel\Models\Run;
use Illuminate\Console\Command;

class RetryRunCommand extends Command
{
    protected $signature = 'clutch:retry
        {run : The run identifier}
        {--reset-budget : Start the new attempt with a fresh budget}';

    protected $description = 'Queue a fresh attempt of a run';

    public function handle(): int
    {
        $run = Run::query()->with('session')->find($this->argument('run'));

        if (! $run instanceof Run) {
            $this->components->error("No run found with the identifier [{$this->argument('run')}].");

            return self::FAILURE;
        }

        if (! $run->status->isTerminal()) {
            $this->components->error(
                "Run [{$run->id}] is still [{$run->status->value}]. Cancel it before retrying."
            );

            return self::FAILURE;
        }

        $retry = $run->retry(resetBudget: (bool) $this->option('reset-budget'));

        $this->components->info("Queued attempt {$retry->attempt} as run [{$retry->id}].");

        if (! $this->option('reset-budget')) {
            $usage = $retry->usage();
            $this->components->warn(
                'The new attempt inherits '.number_format($usage->totalTokens()).' tokens of prior usage. '.
                'Pass --reset-budget to start fresh.'
            );
        }

        return self::SUCCESS;
    }
}
