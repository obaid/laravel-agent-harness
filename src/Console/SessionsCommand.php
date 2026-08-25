<?php

declare(strict_types=1);

namespace Clutch\Laravel\Console;

use Clutch\Laravel\Models\Session;
use Illuminate\Console\Command;

class SessionsCommand extends Command
{
    protected $signature = 'clutch:sessions
        {--status=* : Only show sessions with these statuses}
        {--agent= : Only show sessions for this agent class}
        {--limit=25 : How many sessions to show}';

    protected $description = 'List Clutch sessions';

    public function handle(): int
    {
        $sessions = Session::query()
            ->when($this->option('status'), fn ($query, array $statuses) => $query->whereIn('status', $statuses))
            ->when($this->option('agent'), fn ($query, string $agent) => $query->where('agent_class', 'like', "%{$agent}%"))
            ->latest('created_at')
            ->limit((int) $this->option('limit'))
            ->get();

        if ($sessions->isEmpty()) {
            $this->components->info('No Clutch sessions found.');

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Name', 'Agent', 'Status', 'Active run', 'Runs', 'Created'],
            $sessions->map(fn (Session $session): array => [
                $session->id,
                \Illuminate\Support\Str::limit($session->name ?? '—', 28),
                class_basename($session->agent_class ?? $session->runtime_name ?? '—'),
                $session->status->value,
                $session->active_run_id ?? '—',
                $session->runs()->count(),
                $session->created_at?->diffForHumans() ?? '—',
            ])->all(),
        );

        return self::SUCCESS;
    }
}
