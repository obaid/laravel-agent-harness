<?php

declare(strict_types=1);

namespace Clutch\Laravel\Tests\Fixtures\Workflows;

use Clutch\Laravel\Tests\Fixtures\Agents\ResearchAgent;
use Clutch\Laravel\Workflows\Workflow;

/**
 * The README's workflow example, verbatim, so the documented shape is the one
 * that actually runs.
 */
class OnboardCustomer extends Workflow
{
    protected static ?string $agent = ResearchAgent::class;

    /** @var array<int, string> */
    public static array $provisioned = [];

    public function handle(array $payload): mixed
    {
        $research = $this->step('research', fn () => $this->prompt(
            "Research {$payload['domain']} and summarise how they sell."
        )->text);

        $this->emit('researched', ['chars' => strlen($research)]);

        $decision = $this->pause('sign-off', ['research' => $research]);

        if (! $decision['approved']) {
            return ['skipped' => $decision['reason']];
        }

        return $this->step('provision', fn () => $this->provision($payload, $research));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function provision(array $payload, string $research): array
    {
        static::$provisioned[] = (string) $payload['domain'];

        return ['provisioned' => $payload['domain'], 'notes' => $research];
    }
}
