<?php

declare(strict_types=1);

namespace Clutch\Laravel\Http\Controllers;

use Clutch\Laravel\Models\Run;
use Clutch\Laravel\Runtime\ClutchResult;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Reads and cancels runs.
 */
class RunController
{
    public function show(Request $request, string $run): JsonResponse
    {
        $model = Run::query()->with('session')->findOrFail($run)->authorizeFor($request->user());

        return new JsonResponse(ClutchResult::fromRun($model)->toArray());
    }

    public function events(Request $request, string $run): JsonResponse
    {
        $model = Run::query()->with('session')->findOrFail($run)->authorizeFor($request->user());

        $after = max(0, (int) $request->query('after', 0));
        $limit = min(500, max(1, (int) $request->query('limit', 200)));

        $events = $model->eventsAfter($after, $limit);

        return new JsonResponse([
            'data' => $events->map->toEnvelope()->all(),
            'cursor' => $events->last()->sequence ?? $after,
            'has_more' => $events->count() === $limit,
        ]);
    }

    public function cancel(Request $request, string $run): JsonResponse
    {
        $model = Run::query()->with('session')->findOrFail($run)->authorizeFor($request->user());

        $model->cancel($request->string('reason')->toString() ?: null);

        return new JsonResponse([
            'run_id' => $model->id,
            'status' => $model->refresh()->status->value,
        ]);
    }

    public function retry(Request $request, string $run): JsonResponse
    {
        $model = Run::query()->with('session')->findOrFail($run)->authorizeFor($request->user());

        $retry = $model->retry(resetBudget: $request->boolean('reset_budget'));

        return new JsonResponse([
            'run_id' => $retry->id,
            'attempt' => $retry->attempt,
            'status' => $retry->status->value,
        ], 202);
    }
}
