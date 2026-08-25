<?php

declare(strict_types=1);

namespace Clutch\Laravel\Tests\Fixtures\Workflows;

use Clutch\Laravel\Tests\Fixtures\Agents\ResearchAgent;
use Clutch\Laravel\Workflows\Workflow;

/**
 * The shape most real workflows take: stage an input, call an agent, keep the
 * result as an artifact.
 */
class ResearchWorkflow extends Workflow
{
    protected static ?string $agent = ResearchAgent::class;

    public function produces(): array
    {
        return ['reports/*.md'];
    }

    public function handle(array $payload): mixed
    {
        $this->stage(['brief.txt' => (string) ($payload['brief'] ?? '')]);

        $findings = $this->step('research', fn (): string => $this->prompt(
            'Research this brief: '.$this->workspace()->get('brief.txt'),
        )->text);

        $this->workspace()->put('reports/findings.md', '# Findings'.PHP_EOL.$findings);

        return ['findings' => $findings];
    }
}
