<?php

declare(strict_types=1);

namespace AgentHarness\Laravel\Tests\Fixtures\Tools;

use AgentHarness\Laravel\Contracts\IdempotentTool;
use AgentHarness\Laravel\Contracts\SensitiveTool;
use AgentHarness\Laravel\Data\ToolInvocation;
use AgentHarness\Laravel\Enums\ToolSensitivity;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Concerns\InteractsWithApprovals;
use Laravel\Ai\Contracts\Approvable;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class PublishArticle implements Approvable, IdempotentTool, SensitiveTool, Tool
{
    use InteractsWithApprovals;

    /** @var array<int, int> */
    public static array $published = [];

    public function description(): Stringable|string
    {
        return 'Publish an article to the public site.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'article_id' => $schema->integer()->required(),
        ];
    }

    public function sensitivity(): ToolSensitivity
    {
        return ToolSensitivity::Irreversible;
    }

    public function idempotencyKey(ToolInvocation $invocation): string
    {
        return 'publish-article:'.($invocation->arguments['article_id'] ?? 'unknown');
    }

    public function handle(Request $request): Stringable|string
    {
        static::$published[] = (int) $request['article_id'];

        return 'Published article '.$request['article_id'].'.';
    }
}
