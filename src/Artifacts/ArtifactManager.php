<?php

declare(strict_types=1);

namespace Clutch\Laravel\Artifacts;

use Clutch\Laravel\Enums\EventType;
use Clutch\Laravel\Events\ArtifactCreated;
use Clutch\Laravel\Models\Artifact as ArtifactModel;
use Clutch\Laravel\Models\Run;
use Clutch\Laravel\Runtime\EventStore;
use Clutch\Laravel\Support\Id;
use Illuminate\Support\Facades\Event;

/**
 * Persists artifact metadata and emits the corresponding run event.
 *
 * Artifact bytes are never copied into the database or into event payloads.
 */
class ArtifactManager
{
    public function __construct(protected EventStore $events) {}

    /**
     * Attach an artifact to a run.
     */
    public function add(Run $run, Artifact $artifact): ArtifactModel
    {
        $attributes = $artifact->persist();

        $model = ArtifactModel::query()->create([
            'id' => Id::artifact(),
            'session_id' => $run->session_id,
            'run_id' => $run->id,
            ...$attributes,
        ]);

        $this->events->append($run, EventType::ArtifactCreated, [
            'artifact_id' => $model->id,
            'name' => $model->name,
            'kind' => $model->kind->value,
            'mime_type' => $model->mime_type,
            'size_bytes' => $model->size_bytes,
            'sha256' => $model->sha256,
            'metadata' => $model->metadata,
        ]);

        Event::dispatch(new ArtifactCreated($model));

        return $model;
    }
}
