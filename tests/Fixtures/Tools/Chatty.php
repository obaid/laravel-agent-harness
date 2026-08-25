<?php

declare(strict_types=1);

namespace Clutch\Laravel\Tests\Fixtures\Tools;

use Clutch\Laravel\Contracts\SensitiveTool;
use Clutch\Laravel\Enums\ToolSensitivity;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Returns far more than anyone wants to put in front of a model.
 */
class Chatty implements SensitiveTool, Tool
{
    public function description(): Stringable|string
    {
        return 'Returns a great deal of text.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function sensitivity(): ToolSensitivity
    {
        return ToolSensitivity::ReadOnly;
    }

    public function handle(Request $request): Stringable|string
    {
        return str_repeat("a line of scraped output\n", 200);
    }
}
