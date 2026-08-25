<?php

declare(strict_types=1);

namespace Clutch\Laravel\Tests\Fixtures\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Approvals\Approval;
use Laravel\Ai\Concerns\InteractsWithApprovals;
use Laravel\Ai\Contracts\Approvable;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * A reversible tool that does not ask for approval on its own.
 *
 * Whether it pauses is therefore entirely the harness permission mode's doing,
 * which is what these tests are about.
 */
class DraftEmail implements Approvable, Tool
{
    use InteractsWithApprovals;

    public function description(): Stringable|string
    {
        return 'Draft an email without sending it.';
    }

    public function schema(JsonSchema $schema): array
    {
        return ['subject' => $schema->string()->required()];
    }

    protected function needsApproval(Request $request): Approval|bool
    {
        return false;
    }

    public function handle(Request $request): Stringable|string
    {
        return 'Drafted: '.$request['subject'];
    }
}
