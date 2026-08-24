<?php

declare(strict_types=1);

namespace AgentHarness\Laravel\Console;

use AgentHarness\Laravel\Models\Approval;
use AgentHarness\Laravel\Models\Artifact;
use AgentHarness\Laravel\Models\Run;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class RunCommand extends Command
{
    protected $signature = 'harness:run {run : The run identifier}';

    protected $description = 'Inspect a single harness run';

    public function handle(): int
    {
        $run = Run::query()->with(['session', 'approvals', 'artifacts'])->find($this->argument('run'));

        if (! $run instanceof Run) {
            $this->components->error("No run found with the identifier [{$this->argument('run')}].");

            return self::FAILURE;
        }

        $this->components->twoColumnDetail('<fg=gray>Run</>', $run->id);
        $this->components->twoColumnDetail('Session', $run->session_id);
        $this->components->twoColumnDetail('Agent', class_basename($run->session->agent_class ?? '—'));
        $this->components->twoColumnDetail('Status', $this->colorStatus($run->status->value));
        $this->components->twoColumnDetail('Attempt', (string) $run->attempt);
        $this->components->twoColumnDetail('Events', (string) $run->last_event_sequence);
        $this->components->twoColumnDetail('Started', $run->started_at?->toDateTimeString() ?? '—');
        $this->components->twoColumnDetail('Finished', $run->finished_at?->toDateTimeString() ?? '—');

        $usage = $run->usage();
        $this->components->twoColumnDetail('Steps / tool calls', "{$usage->steps} / {$usage->toolCalls}");
        $this->components->twoColumnDetail('Tokens', number_format($usage->totalTokens()));
        $this->components->twoColumnDetail('Estimated cost', '$'.number_format($usage->costUsd, 4));

        if ($run->failure_message !== null) {
            $this->newLine();
            $this->components->error("[{$run->failure_category?->value}] {$run->failure_message}");
        }

        $this->renderPrompt($run);
        $this->renderOutput($run);
        $this->renderApprovals($run);
        $this->renderArtifacts($run);

        return self::SUCCESS;
    }

    protected function renderPrompt(Run $run): void
    {
        $prompt = $run->promptText();

        if ($prompt === '') {
            return;
        }

        $this->newLine();
        $this->components->info('Prompt');
        $this->line('  '.Str::limit($prompt, 400));
    }

    protected function renderOutput(Run $run): void
    {
        if (blank($run->output_text)) {
            return;
        }

        $this->newLine();
        $this->components->info('Output');
        $this->line('  '.Str::limit((string) $run->output_text, 800));
    }

    protected function renderApprovals(Run $run): void
    {
        if ($run->approvals->isEmpty()) {
            return;
        }

        $this->newLine();
        $this->components->info('Approvals');

        $this->table(
            ['ID', 'Tool', 'Status', 'Requested', 'Resolved by'],
            $run->approvals->map(fn (Approval $approval): array => [
                $approval->id,
                $approval->tool_name,
                $approval->status->value,
                $approval->requested_at?->diffForHumans() ?? '—',
                $approval->resolved_by_id === null
                    ? '—'
                    : class_basename((string) $approval->resolved_by_type).' #'.$approval->resolved_by_id,
            ])->all(),
        );
    }

    protected function renderArtifacts(Run $run): void
    {
        if ($run->artifacts->isEmpty()) {
            return;
        }

        $this->newLine();
        $this->components->info('Artifacts');

        $this->table(
            ['ID', 'Name', 'Kind', 'Size', 'Path'],
            $run->artifacts->map(fn (Artifact $artifact): array => [
                $artifact->id,
                $artifact->name,
                $artifact->kind->value,
                $artifact->size_bytes === null ? '—' : number_format($artifact->size_bytes / 1024, 1).' KB',
                "{$artifact->disk}:{$artifact->path}",
            ])->all(),
        );
    }

    protected function colorStatus(string $status): string
    {
        return match ($status) {
            'completed' => "<fg=green>{$status}</>",
            'failed', 'budget_exceeded' => "<fg=red>{$status}</>",
            'cancelled' => "<fg=yellow>{$status}</>",
            'awaiting_approval' => "<fg=magenta>{$status}</>",
            default => "<fg=cyan>{$status}</>",
        };
    }
}
