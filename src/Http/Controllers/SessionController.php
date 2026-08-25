<?php

declare(strict_types=1);

namespace Clutch\Laravel\Http\Controllers;

use Clutch\Laravel\Models\Session;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Reads sessions belonging to the authenticated participant.
 */
class SessionController
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $sessions = $user === null
            ? Session::query()->whereNull('participant_id')->limit(0)->get()
            : Session::query()->forParticipant($user)->latest('created_at')->limit(50)->get();

        return new JsonResponse([
            'data' => $sessions->map(fn (Session $session): array => $this->present($session))->all(),
        ]);
    }

    public function show(Request $request, string $session): JsonResponse
    {
        $model = Session::query()->findOrFail($session)->authorizeFor($request->user());

        return new JsonResponse($this->present($model, withRuns: true));
    }

    /**
     * @return array<string, mixed>
     */
    protected function present(Session $session, bool $withRuns = false): array
    {
        return array_filter([
            'id' => $session->id,
            'name' => $session->name,
            'status' => $session->status->value,
            'agent' => $session->agent_class,
            'driver' => $session->driver,
            'permission_mode' => $session->permission_mode->value,
            'active_run_id' => $session->active_run_id,
            'created_at' => $session->created_at?->toISOString(),
            'runs' => $withRuns
                ? $session->runs()->limit(20)->get()->map(fn ($run): array => [
                    'id' => $run->id,
                    'status' => $run->status->value,
                    'attempt' => $run->attempt,
                    'created_at' => $run->created_at?->toISOString(),
                ])->all()
                : null,
        ], fn (mixed $value): bool => $value !== null);
    }
}
