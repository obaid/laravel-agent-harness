<?php

declare(strict_types=1);

namespace AgentHarness\Laravel\Artifacts;

use AgentHarness\Laravel\Models\Artifact as ArtifactModel;
use AgentHarness\Laravel\Models\Run;
use Illuminate\Support\Collection;

/**
 * The run-scoped handle tools use to attach outputs.
 *
 * Obtained through the ambient run context, so a tool never needs to know a
 * run identifier or reach into harness storage.
 */
class ArtifactRegistrar
{
    /** @var Collection<int, ArtifactModel> */
    protected Collection $registered;

    public function __construct(
        protected Run $run,
        protected ArtifactManager $artifacts,
    ) {
        $this->registered = new Collection;
    }

    /**
     * Attach an artifact to the current run.
     */
    public function add(Artifact $artifact): ArtifactModel
    {
        return tap($this->artifacts->add($this->run, $artifact), function (ArtifactModel $model): void {
            $this->registered->push($model);
        });
    }

    /**
     * Everything attached during this turn.
     *
     * @return Collection<int, ArtifactModel>
     */
    public function all(): Collection
    {
        return $this->registered;
    }
}
