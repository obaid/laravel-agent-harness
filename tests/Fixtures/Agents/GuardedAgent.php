<?php

declare(strict_types=1);

namespace Clutch\Laravel\Tests\Fixtures\Agents;

use Clutch\Laravel\Facades\Clutch;
use Clutch\Laravel\Tests\Fixtures\Tools\PublishArticle;
use Clutch\Laravel\Tests\Fixtures\Tools\SearchWeb;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\RemembersConversations as RemembersConversationsContract;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * An agent wired the way the documentation says to wire one.
 *
 * Its whole job is to be the positive case: tools returned through
 * Clutch::policy(), so the harness is actually in front of them.
 */
class GuardedAgent implements Agent, HasTools, RemembersConversationsContract
{
    use Promptable;
    use RemembersConversations;

    public function instructions(): Stringable|string
    {
        return 'You publish things, carefully.';
    }

    public function tools(): iterable
    {
        return Clutch::policy([new SearchWeb, new PublishArticle]);
    }
}
