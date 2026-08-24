<?php

declare(strict_types=1);

use AgentHarness\Laravel\Contracts\EventSerializer;
use AgentHarness\Laravel\Runtime\Redactor;

beforeEach(function (): void {
    $this->redactor = new Redactor(['authorization', 'api_key', 'token', 'password']);
});

it('replaces configured keys at any depth', function (): void {
    $redacted = $this->redactor->redact([
        'tool' => 'send_email',
        'arguments' => [
            'to' => 'taylor@example.com',
            'headers' => ['authorization' => 'Bearer sk-live-123'],
        ],
        'api_key' => 'sk-live-456',
    ]);

    expect($redacted['arguments']['headers']['authorization'])->toBe('[REDACTED]')
        ->and($redacted['api_key'])->toBe('[REDACTED]')
        ->and($redacted['arguments']['to'])->toBe('taylor@example.com');
});

it('matches keys that merely contain a configured term', function (): void {
    $redacted = $this->redactor->redact([
        'openai_api_key' => 'sk-1',
        'refresh_token' => 'rt-1',
        'user_password_hash' => 'hash',
    ]);

    expect($redacted)->toBe([
        'openai_api_key' => '[REDACTED]',
        'refresh_token' => '[REDACTED]',
        'user_password_hash' => '[REDACTED]',
    ]);
});

it('leaves ordinary values untouched', function (): void {
    $payload = ['tool' => 'search', 'arguments' => ['query' => 'laravel agents'], 'count' => 3];

    expect($this->redactor->redact($payload))->toBe($payload);
});

it('stops recursing at a depth limit rather than looping forever', function (): void {
    $deep = ['level' => 1];
    $cursor = &$deep;

    for ($i = 0; $i < 30; $i++) {
        $cursor['child'] = ['level' => $i + 2];
        $cursor = &$cursor['child'];
    }

    $redacted = (new Redactor([], [], maxDepth: 5))->redact($deep);

    expect(json_encode($redacted))->toContain('_truncated');
});

it('detects a payload that still carries a secret', function (): void {
    expect($this->redactor->containsSensitiveKeys(['nested' => ['api_key' => 'sk-1']]))->toBeTrue()
        ->and($this->redactor->containsSensitiveKeys(['nested' => ['api_key' => '[REDACTED]']]))->toBeFalse()
        ->and($this->redactor->containsSensitiveKeys(['query' => 'laravel']))->toBeFalse();
});

it('applies an application serializer before redacting', function (): void {
    $serializer = new class implements EventSerializer
    {
        public function serialize(array $payload): array
        {
            // Only these fields are approved for the event history.
            return [
                'tool' => $payload['tool'] ?? null,
                'arguments' => ['recipient_count' => count($payload['arguments']['to'] ?? [])],
            ];
        }
    };

    $redactor = new Redactor(['token'], ['send_email' => $serializer]);

    $result = $redactor->redactToolPayload('send_email', [
        'tool' => 'send_email',
        'arguments' => ['to' => ['a@b.com', 'c@d.com'], 'body' => 'Confidential terms'],
        'token' => 'secret',
    ]);

    expect($result)->toBe([
        'tool' => 'send_email',
        'arguments' => ['recipient_count' => 2],
    ]);

    expect(json_encode($result))->not->toContain('Confidential');
});
