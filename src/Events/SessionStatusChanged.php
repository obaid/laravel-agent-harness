<?php

declare(strict_types=1);

namespace Clutch\Laravel\Events;

use Clutch\Laravel\Enums\SessionStatus;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SessionStatusChanged
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly string $sessionId,
        public readonly ?SessionStatus $from,
        public readonly SessionStatus $to,
    ) {}
}
