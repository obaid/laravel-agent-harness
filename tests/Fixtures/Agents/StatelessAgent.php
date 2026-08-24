<?php

declare(strict_types=1);

namespace AgentHarness\Laravel\Tests\Fixtures\Agents;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * A deliberately single-turn agent: no RemembersConversations trait, so Laravel
 * AI never persists its conversation.
 */
class StatelessAgent implements Agent
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return 'You answer a single question and stop.';
    }
}
