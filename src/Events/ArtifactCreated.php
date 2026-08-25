<?php

declare(strict_types=1);

namespace Clutch\Laravel\Events;

use Clutch\Laravel\Models\Artifact;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ArtifactCreated
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public readonly Artifact $artifact) {}
}
