<?php

declare(strict_types=1);

namespace AgentHarness\Laravel\Console;

use AgentHarness\Laravel\Jobs\PruneAgentHarnessRecords;
use Illuminate\Console\Command;

class PruneCommand extends Command
{
    protected $signature = 'harness:prune
        {--batch=1000 : How many records of each type to remove per pass}
        {--passes=1 : How many passes to run}
        {--delete-files : Also delete artifact bytes from storage}';

    protected $description = 'Prune aged harness records within the configured retention windows';

    public function handle(): int
    {
        $totals = [];

        for ($pass = 0; $pass < (int) $this->option('passes'); $pass++) {
            $removed = (new PruneAgentHarnessRecords(
                batchSize: (int) $this->option('batch'),
                deleteArtifactFiles: (bool) $this->option('delete-files'),
            ))->handle();

            foreach ($removed as $type => $count) {
                $totals[$type] = ($totals[$type] ?? 0) + $count;
            }
        }

        if (array_sum($totals) === 0) {
            $this->components->info('Nothing was old enough to prune.');

            return self::SUCCESS;
        }

        $this->table(
            ['Record type', 'Removed'],
            collect($totals)->map(fn (int $count, string $type): array => [$type, number_format($count)])->values()->all(),
        );

        return self::SUCCESS;
    }
}
