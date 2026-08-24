<?php

declare(strict_types=1);

namespace AgentHarness\Laravel\Console;

use AgentHarness\Laravel\Models\Run;
use AgentHarness\Laravel\Models\RunEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class EventsCommand extends Command
{
    protected $signature = 'harness:events
        {run : The run identifier}
        {--after=0 : Only show events after this sequence}
        {--type=* : Only show these event types}
        {--limit=100 : How many events to show}
        {--json : Output the raw event envelopes}
        {--payloads : Show full payloads rather than a summary}';

    protected $description = 'Replay the recorded events for a run';

    public function handle(): int
    {
        $run = Run::query()->find($this->argument('run'));

        if (! $run instanceof Run) {
            $this->components->error("No run found with the identifier [{$this->argument('run')}].");

            return self::FAILURE;
        }

        $events = $run->events()
            ->where('sequence', '>', (int) $this->option('after'))
            ->when($this->option('type'), fn ($query, array $types) => $query->whereIn('type', $types))
            ->limit((int) $this->option('limit'))
            ->get();

        if ($events->isEmpty()) {
            $this->components->info('No events recorded for this run yet.');

            return self::SUCCESS;
        }

        if ($this->option('json')) {
            $this->line((string) json_encode($events->map->toEnvelope()->all(), JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        foreach ($events as $event) {
            $this->renderEvent($event);
        }

        return self::SUCCESS;
    }

    protected function renderEvent(RunEvent $event): void
    {
        $sequence = str_pad((string) $event->sequence, 4, ' ', STR_PAD_LEFT);
        $time = $event->occurred_at?->format('H:i:s.v') ?? '';

        $this->line(sprintf(
            '<fg=gray>%s</> <fg=gray>%s</> <fg=cyan>%s</> %s',
            $sequence,
            $time,
            $event->type->value,
            $this->summarize($event),
        ));
    }

    /**
     * Payloads are already redacted in storage; this only decides how much to show.
     */
    protected function summarize(RunEvent $event): string
    {
        $payload = $event->payload ?? [];

        if ($this->option('payloads')) {
            return (string) json_encode($payload);
        }

        return match ($event->type->value) {
            'text.delta', 'reasoning.delta' => Str::limit((string) ($payload['delta'] ?? ''), 80),
            'tool.call.requested' => (string) ($payload['tool'] ?? '').' '.Str::limit((string) json_encode($payload['arguments'] ?? []), 60),
            'tool.call.completed' => (string) ($payload['tool'] ?? '').' → '.Str::limit((string) json_encode($payload['result'] ?? null), 60),
            'tool.call.failed' => (string) ($payload['tool'] ?? '').' ✗ '.Str::limit((string) ($payload['error'] ?? ''), 60),
            'approval.requested' => 'awaiting '.(string) ($payload['tool'] ?? 'tool'),
            'approval.resolved' => (string) ($payload['tool'] ?? '').' → '.(string) ($payload['status'] ?? ''),
            'run.completed' => Str::limit((string) ($payload['text'] ?? ''), 80),
            'run.failed' => (string) ($payload['message'] ?? ''),
            'usage.updated' => 'tokens='.(string) ($payload['usage']['total_tokens'] ?? 0),
            default => Str::limit((string) json_encode($payload), 80),
        };
    }
}
