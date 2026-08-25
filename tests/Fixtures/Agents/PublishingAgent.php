<?php

declare(strict_types=1);

namespace Clutch\Laravel\Tests\Fixtures\Agents;

use Clutch\Laravel\Tests\Fixtures\Tools\PublishArticle;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\RemembersConversations as RemembersConversationsContract;
use Laravel\Ai\Promptable;
use Stringable;

class PublishingAgent implements Agent, HasTools, RemembersConversationsContract
{
    use Promptable;
    use RemembersConversations;

    public function instructions(): Stringable|string
    {
        return 'You draft and publish articles. Publishing requires approval.';
    }

    public function tools(): iterable
    {
        return [
            (new PublishArticle)->requireApproval('Publishing is irreversible.'),
        ];
    }
}
