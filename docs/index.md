---
layout: default
title: Home
nav_order: 0
permalink: /
---

# Laravel Agent Harness
{: .fs-9 }

Durable, observable agent runtimes for Laravel, powered by the official Laravel AI SDK.
{: .fs-6 .fw-300 }

[Get started](guide/){: .btn .btn-primary .fs-5 .mb-4 .mb-md-0 .mr-2 }
[View on GitHub](https://github.com/obaid/laravel-agent-harness){: .btn .fs-5 .mb-4 .mb-md-0 }

---

## The problem

The Laravel AI SDK makes it simple to prompt an agent:

```php
$response = (new ResearchAgent)->prompt('Research our competitors.');
```

That is the agent **engine**. But a production agent rarely fits inside one
request and one response. The user closes the browser. A deploy restarts the
worker. A publishing tool succeeds, then the worker dies before recording it.
The agent pauses for approval and gets an answer hours later, in another
process.

Laravel AI does not try to be the durable runtime that owns that lifecycle.
This package is.

```php
$session = Harness::agent(ResearchAgent::class)->for($user)->create();

$result = $session->prompt('Research our competitors and recommend a wedge.');
```

Same shape. The difference is what survives it: the session, the run, every
event in order, the usage, the artifacts, and the ability to pick all of it back
up in another process tomorrow.

---

## What you get

| | |
|:--|:--|
| **Durable sessions** | Context that outlives a request, a worker, and a deploy. |
| **Ordered events** | An append-only account of every run, replayable from a cursor. |
| **Human approval** | Runs that pause, release the worker, and resume when a decision lands. |
| **Budgets** | Step, tool, token, cost and duration ceilings that carry across retries. |
| **Cancellation** | Cooperative and durable, honest about what it cannot interrupt. |
| **Artifacts** | Durable outputs with integrity hashes and authorized downloads. |
| **Idempotent tools** | A ledger that stops a retry repeating a side effect. |
| **Recovery** | Detection of lost workers, and retry as a new attempt. |

---

## Documentation

- **[Guide](guide/)** — installation, the mental model, and every feature with examples.
- **[Recipes](recipes/)** — complete slices: approval inboxes, live progress UIs, tenancy, spend caps.
- **[Architecture](architecture/)** — runtime boundaries, state machines, storage, reliability, security.
- **[Development](development/)** — repository layout, testing strategy, and how to add a driver.

---

## Installation

```bash
composer require obaid/laravel-agent-harness

php artisan vendor:publish --provider="AgentHarness\Laravel\AgentHarnessServiceProvider"
php artisan vendor:publish --provider="Laravel\Ai\AiServiceProvider"

php artisan migrate
```

Requires PHP 8.3+, Laravel 12 or 13, and `laravel/ai` 0.11+.
