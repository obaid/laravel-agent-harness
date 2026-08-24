<?php

declare(strict_types=1);

namespace AgentHarness\Laravel\Tests\Fixtures\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Contracts\RemembersConversations as RemembersConversationsContract;
use Laravel\Ai\Promptable;
use Stringable;

class ScoringAgent implements Agent, HasStructuredOutput, RemembersConversationsContract
{
    use Promptable;
    use RemembersConversations;

    public function instructions(): Stringable|string
    {
        return 'You score drafts against the content rubric.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'score' => $schema->integer()->required(),
            'notes' => $schema->string()->required(),
        ];
    }
}
