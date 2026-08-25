<?php

declare(strict_types=1);

namespace Clutch\Laravel\Console;

use Clutch\Laravel\Models\Run;
use Illuminate\Console\Command;

class CancelRunCommand extends Command
{
    protected $signature = 'clutch:cancel {run : The run identifier} {--reason= : Why the run is being cancelled}';

    protected $description = 'Request cooperative cancellation of a run';

    public function handle(): int
    {
        $run = Run::query()->with('session')->find($this->argument('run'));

        if (! $run instanceof Run) {
            $this->components->error("No run found with the identifier [{$this->argument('run')}].");

            return self::FAILURE;
        }

        if ($run->status->isTerminal()) {
            $this->components->warn("Run [{$run->id}] already finished as [{$run->status->value}].");

            return self::SUCCESS;
        }

        $run->cancel($this->option('reason') ?: 'Cancelled from the console.');

        $status = $run->refresh()->status->value;

        $this->components->info(
            $status === 'cancelled'
                ? "Run [{$run->id}] was cancelled."
                : "Cancellation requested for run [{$run->id}]; it is now [{$status}] and will stop at its next safe boundary."
        );

        return self::SUCCESS;
    }
}
