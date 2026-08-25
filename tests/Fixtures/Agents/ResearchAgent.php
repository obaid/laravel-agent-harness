<?php

declare(strict_types=1);

namespace Clutch\Laravel\Tests\Fixtures\Agents;

use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\RemembersConversations as RemembersConversationsContract;
use Laravel\Ai\Promptable;
use Stringable;

class ResearchAgent implements Agent, RemembersConversationsContract
{
    use Promptable;
    use RemembersConversations;

    public function instructions(): Stringable|string
    {
        return 'You research competitors and write positioning briefs.';
    }
}
