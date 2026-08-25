---
layout: home
title: Home
nav_order: 0
permalink: /
---

Laravel AI gives you the agent. Clutch is the harness around it: sessions that outlive a request, runs you can queue and resume, an ordered event history you can replay, human approvals that survive a deploy, budgets, cancellation, and artifacts.

## The problem

Prompting an agent with Laravel AI is already easy.

```php
$response = (new ResearchAgent)->prompt('Research our competitors.');
```

That is the engine. The trouble starts when the work does not fit in one request. Someone closes the browser. A deploy restarts the worker. A publishing tool succeeds, then the process dies before recording that it did. The agent pauses for approval and gets an answer the next morning, from somewhere else entirely.

Laravel AI does not try to be the harness that owns that lifecycle. Clutch is.

```php
$session = Clutch::agent(ResearchAgent::class)->for($user)->create();

$result = $session->prompt('Research our competitors and recommend a wedge.');
```

The call has the same shape. What differs is what remains afterward: a session you can continue tomorrow, a run record with usage and cost, and every event in order.

## What it handles

Durable sessions keep context alive across a request, a worker, and a deploy. Every run leaves an append only history you can replay from any cursor, which is what makes a reconnecting browser cheap. Runs pause for human approval by releasing the worker entirely and picking back up when a decision lands, hours later if that is how long it takes.

Budgets cover steps, tool calls, tokens, cost, and duration, and they carry across retries so a failing loop cannot spend the same ceiling twice. Cancellation is cooperative and durable, and honest about the tools it cannot interrupt. Artifacts get integrity hashes and authorized downloads. A ledger keeps a retried tool from firing its side effect a second time. When a worker vanishes, the harness notices and retries the work as a new attempt rather than reopening a finished record.

## Install

```bash
composer require obaid/laravel-clutch

php artisan vendor:publish --provider="Clutch\Laravel\ClutchServiceProvider"
php artisan vendor:publish --provider="Laravel\Ai\AiServiceProvider"

php artisan migrate
```

Requires PHP 8.3 or newer, Laravel 12 or 13, and `laravel/ai` 0.11 or newer.

## Or don't read any of this

Paste a prompt into your coding agent and let it install and wire up the package for you. The [setup prompts](agents/) cover a fresh install, moving an agent you already have across, and adding approval to a single tool.

Agents can read the package quickly from [llms.txt](llms.txt), or take the guide and every recipe in one file from [llms-full.txt](llms-full.txt).
