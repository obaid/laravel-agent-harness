<?php

declare(strict_types=1);

namespace AgentHarness\Laravel\Tests\Fixtures\Tools;

use AgentHarness\Laravel\Contracts\SensitiveTool;
use AgentHarness\Laravel\Enums\ToolSensitivity;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * A read-only tool: nothing to approve, under any mode.
 */
class SearchWeb implements SensitiveTool, Tool
{
    public function description(): Stringable|string
    {
        return 'Search the web for a query.';
    }

    public function schema(JsonSchema $schema): array
    {
        return ['query' => $schema->string()->required()];
    }

    public function sensitivity(): ToolSensitivity
    {
        return ToolSensitivity::ReadOnly;
    }

    public function handle(Request $request): Stringable|string
    {
        return '12 results for '.$request['query'];
    }
}
