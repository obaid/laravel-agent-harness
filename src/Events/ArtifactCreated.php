<?php

declare(strict_types=1);

namespace AgentHarness\Laravel\Events;

use AgentHarness\Laravel\Models\Artifact;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ArtifactCreated
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public readonly Artifact $artifact) {}
}
