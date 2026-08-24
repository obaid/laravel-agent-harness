# Recipes

End-to-end examples. Each one is a complete slice you can lift into an application, and each builds on ordinary [Laravel AI](https://laravel.com/docs/ai) agents and tools.

- [1. A research agent with an approval inbox](#1-a-research-agent-with-an-approval-inbox)
- [2. A live progress UI that survives a refresh](#2-a-live-progress-ui-that-survives-a-refresh)
- [3. Multi-tenant agents](#3-multi-tenant-agents)
- [4. Capping spend per customer plan](#4-capping-spend-per-customer-plan)
- [5. A tool that must never double-charge](#5-a-tool-that-must-never-double-charge)
- [6. Nightly batch runs](#6-nightly-batch-runs)
- [7. A structured grading agent](#7-a-structured-grading-agent)
- [8. Testing the whole thing](#8-testing-the-whole-thing)

---

## 1. A research agent with an approval inbox

The scenario: an agent researches competitors and drafts a blog post. Publishing is irreversible, so a human signs off — possibly the next morning, from a different device.

### The agent

```php
namespace App\Ai\Agents;

use App\Ai\Tools\PublishArticle;
use App\Ai\Tools\SearchWeb;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\RemembersConversations as RemembersConversationsContract;
use Laravel\Ai\Promptable;
use Stringable;

class ContentAgent implements Agent, HasTools, RemembersConversationsContract
{
    use Promptable;
    use RemembersConversations;

    public function instructions(): Stringable|string
    {
        return <<<'PROMPT'
        You research a topic and draft a blog post in our house style.
        Publish only when you are confident the draft is finished.
        PROMPT;
    }

    public function tools(): iterable
    {
        return [
            new SearchWeb,
            (new PublishArticle)->requireApproval('Publishing is public and irreversible.'),
        ];
    }
}
```

`requireApproval()` is Laravel AI's own API. The harness picks it up and turns the pause into a durable record.

### The tool

```php
namespace App\Ai\Tools;

use AgentHarness\Laravel\Contracts\IdempotentTool;
use AgentHarness\Laravel\Contracts\SensitiveTool;
use AgentHarness\Laravel\Data\ToolInvocation;
use AgentHarness\Laravel\Enums\ToolSensitivity;
use App\Models\Article;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Concerns\InteractsWithApprovals;
use Laravel\Ai\Contracts\Approvable;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class PublishArticle implements Approvable, IdempotentTool, SensitiveTool, Tool
{
    use InteractsWithApprovals;

    public function description(): Stringable|string
    {
        return 'Publish a drafted article to the public blog.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'article_id' => $schema->integer()->description('The draft to publish.')->required(),
        ];
    }

    public function sensitivity(): ToolSensitivity
    {
        return ToolSensitivity::Irreversible;
    }

    public function idempotencyKey(ToolInvocation $invocation): string
    {
        // Key the side effect, not the call: two retries of "publish 42" must
        // collide, even though their tool-call IDs differ.
        return "publish-article:{$invocation->arguments['article_id']}";
    }

    public function handle(Request $request): Stringable|string
    {
        $article = Article::findOrFail($request['article_id']);

        $article->publish();

        return "Published \"{$article->title}\".";
    }
}
```

### Starting the work

```php
Route::post('/content/research', function (Request $request) {
    $session = Harness::agent(ContentAgent::class)
        ->for($request->user())
        ->tenant($request->user()->currentTeam)
        ->name("Blog post: {$request->topic}")
        ->create();

    $run = $session->queue("Research {$request->topic} and draft a post for our blog.");

    return response()->accepted([
        'session_id' => $session->id,
        'run_id' => $run->id,
    ]);
});
```

The HTTP request returns immediately. A queue worker picks the run up, the agent researches, drafts, and reaches `publish_article` — at which point the run pauses, a checkpoint is written, and **the worker exits normally**. Nothing is held open waiting for a human.

### Notifying the approver

```php
// app/Providers/AppServiceProvider.php

use AgentHarness\Laravel\Events\ApprovalRequested;

Event::listen(ApprovalRequested::class, function (ApprovalRequested $event) {
    $event->approval->session->participant?->notify(
        new AgentAwaitingApproval($event->approval)
    );
});
```

### The inbox

```php
Route::get('/approvals', function (Request $request) {
    return view('approvals.index', [
        'approvals' => Harness::pendingApprovalsFor($request->user()),
    ]);
});
```

```blade
@foreach ($approvals as $approval)
    <div class="approval">
        <h3>{{ $approval->tool_name }}</h3>
        <p>{{ $approval->reason }}</p>
        <pre>{{ json_encode($approval->arguments, JSON_PRETTY_PRINT) }}</pre>

        <form method="POST"
              action="/api/agent-harness/runs/{{ $approval->run_id }}/approvals/{{ $approval->id }}/approve">
            @csrf
            <input name="reason" placeholder="Why are you approving this?">
            <button>Approve</button>
        </form>
    </div>
@endforeach
```

The bundled endpoints already authorize by participant, resolve idempotently, and re-queue the run once every decision is in. If you would rather own the surface, do it yourself:

```php
Route::post('/approvals/{approval}', function (Request $request, string $approvalId) {
    $approval = Approval::findOrFail($approvalId);
    $run = Harness::run($approval->run_id)->authorizeFor($request->user());

    $request->boolean('approved')
        ? $run->approve($approvalId, $request->reason, $request->user())
        : $run->reject($approvalId, $request->reason, $request->user());

    Harness::coordinator()->resumeAfterApproval($run->refresh());

    return back();
});
```

**What you get for free:** the decision survives a deploy, a double-click cannot publish twice, reversing a decision raises instead of silently winning, and the approver and their reason are in the audit history.

---

## 2. A live progress UI that survives a refresh

The scenario: a long run, a progress bar, and a user who refreshes the page halfway through.

### Server

Two endpoints. Start the work, then stream it:

```php
Route::post('/runs', function (Request $request) {
    $session = Harness::session($request->session_id)->authorizeFor($request->user());

    return ['run_id' => $session->queue($request->prompt)->id];
});
```

The event stream endpoint ships with the package:

```
GET /api/agent-harness/runs/{run}/events?after={cursor}
```

### Client

```js
function follow(runId, onEvent) {
  // Resume from where we left off, not from zero.
  let cursor = Number(localStorage.getItem(`run:${runId}`) ?? 0)
  const seen = new Set()

  const source = new EventSource(
    `/api/agent-harness/runs/${runId}/events?after=${cursor}`
  )

  source.onmessage = (message) => {
    if (message.data === '[DONE]') return source.close()

    const event = JSON.parse(message.data)

    // Delivery is at least once; storage is exactly once and ordered.
    const key = `${event.run_id}:${event.sequence}`
    if (seen.has(key)) return
    seen.add(key)

    cursor = event.sequence
    localStorage.setItem(`run:${runId}`, cursor)

    onEvent(event)
  }

  // A connection is held for a bounded time, then asks you to come back.
  source.addEventListener('timeout', () => {
    source.close()
    follow(runId, onEvent)
  })
}

follow(runId, (event) => {
  switch (event.type) {
    case 'text.delta':          appendText(event.payload.delta); break
    case 'tool.call.requested':  showStep(`Running ${event.payload.tool}…`); break
    case 'tool.call.completed':  completeStep(event.payload.tool); break
    case 'approval.requested':   showApprovalPrompt(event.payload); break
    case 'artifact.created':     addDownload(event.payload); break
    case 'run.completed':        finish(event.payload.text); break
    case 'run.failed':           showError(event.payload.message); break
  }
})
```

Refresh the page and the stream picks up at the stored cursor with no gap and no repeat. Close the laptop for an hour and it still works, because the events are rows, not a socket.

### Already using the Vercel AI SDK?

Stream a synchronous run straight into `useChat`:

```php
Route::post('/chat', function (Request $request) {
    return Harness::session($request->session_id)
        ->authorizeFor($request->user())
        ->stream($request->message)
        ->usingVercelDataProtocol();
});
```

You still get the full durable event history behind it — the protocol is a view over the same recorded events, not a separate path.

---

## 3. Multi-tenant agents

Scope every session to a team, and the harness enforces it on every lookup, route, and broadcast channel:

```php
$session = Harness::agent(SupportAgent::class)
    ->for($request->user())
    ->tenant($request->user()->currentTeam)
    ->create();
```

Querying stays ordinary Eloquent:

```php
use AgentHarness\Laravel\Models\Session;

$teamSessions = Session::query()
    ->forTenant($team)
    ->withStatus(SessionStatus::Ready, SessionStatus::AwaitingApproval)
    ->latest()
    ->get();
```

Every packaged route authorizes against the session's participant, so a run belonging to another user is not merely hidden from a list — it is unreachable. Broadcast channels authorize the same way:

```php
// Registered for you when broadcasting is on.
Broadcast::channel('agent-harness.run.{runId}', fn ($user, $runId) => /* participant check */);
```

Tenant-scoped agents usually want tenant-scoped tools. The ambient run context carries the scope without you threading it through constructors:

```php
use AgentHarness\Laravel\Runtime\RunContext;

public function handle(Request $request): string
{
    $context = RunContext::current();

    $team = Team::findOrFail($context->invocationFor('search_tickets', 'x')->tenantId);

    return $team->tickets()->search($request['query'])->take(5)->toJson();
}
```

---

## 4. Capping spend per customer plan

Tell the harness what your models cost:

```php
// config/agent-harness.php
'pricing' => [
    'anthropic:claude-sonnet-4-5' => ['input' => 3.00, 'output' => 15.00],
    'anthropic:claude-haiku-4-5'  => ['input' => 1.00, 'output' => 5.00],
    'openai:gpt-5'                => ['input' => 1.25, 'output' => 10.00],
],
```

Then budget by plan:

```php
use AgentHarness\Laravel\ValueObjects\RunBudget;

$budget = match ($user->plan) {
    'free' => new RunBudget(maxSteps: 10, maxTokens: 50_000, maxCostUsd: 0.25),
    'pro'  => new RunBudget(maxSteps: 40, maxTokens: 250_000, maxCostUsd: 5.00),
    default => new RunBudget(maxDurationSeconds: 1800),
};

$session = Harness::agent(ResearchAgent::class)
    ->for($user)
    ->budget($budget)
    ->create();
```

A run that hits the ceiling stops at `budget_exceeded` with an event naming the limit that ran out:

```json
{
  "type": "run.budget_exceeded",
  "payload": {
    "limit": "max_cost_usd",
    "max": 0.25,
    "used": 0.2513,
    "usage": { "steps": 7, "total_tokens": 41208, "cost_usd": 0.2513 }
  }
}
```

Bill from the same numbers:

```php
Event::listen(HarnessEventRecorded::class, function ($event) {
    if ($event->type !== 'run.completed') {
        return;
    }

    $run = Harness::run($event->runId);

    $run->session->participant?->recordUsage(
        tokens: $run->usage()->totalTokens(),
        costUsd: $run->usage()->costUsd,
    );
});
```

Because usage carries across attempts, a run that fails and retries three times cannot spend the cap three times.

---

## 5. A tool that must never double-charge

The hardest failure to reason about: the tool succeeded, then the worker died before recording it.

```php
class ChargeCustomer implements IdempotentTool, SensitiveTool, Tool
{
    public function idempotencyKey(ToolInvocation $invocation): string
    {
        // The run ID scopes it to this piece of work; the invoice ID makes two
        // charges for the same invoice collide even across retries.
        return "charge:{$invocation->runId}:{$invocation->arguments['invoice_id']}";
    }

    public function sensitivity(): ToolSensitivity
    {
        return ToolSensitivity::Irreversible;
    }

    public function handle(Request $request): Stringable|string
    {
        $invoice = Invoice::findOrFail($request['invoice_id']);

        // Pass the key through to the payment provider too. Belt and braces:
        // the ledger protects you inside the harness, this protects you if the
        // request itself is what got retried.
        $charge = Stripe::charge($invoice, idempotencyKey: $this->keyFor($invoice));

        return "Charged {$invoice->formattedTotal()} ({$charge->id}).";
    }
}
```

The harness writes the key and a `pending` row **before** calling `handle()`, then updates it to `completed` with the result afterwards. A retry finds the completed row and returns the stored result without calling `handle()` again.

Inspect the ledger like any other table:

```php
use AgentHarness\Laravel\Models\ToolExecution;

ToolExecution::query()
    ->where('tool_name', 'charge_customer')
    ->where('status', ToolExecution::FAILED)
    ->latest()
    ->get();
```

**A tool with no idempotency contract is recorded for audit, but the harness makes no duplicate-suppression claim on its behalf.** It cannot safely make one — only you know what "the same action" means for your side effect.

---

## 6. Nightly batch runs

```php
// routes/console.php

Schedule::call(function () {
    Team::query()->whereHas('subscription')->each(function (Team $team) {
        $session = Harness::agent(ReportingAgent::class)
            ->for($team->owner)
            ->tenant($team)
            ->name("Weekly report: {$team->name}")
            ->onQueue('reports')
            ->budget(new RunBudget(maxCostUsd: 2.00, maxDurationSeconds: 600))
            ->create();

        $session->queue('Produce this week\'s performance report as a PDF.');
    });
})->weeklyOn(1, '06:00');
```

Have the agent's tool attach the PDF:

```php
use AgentHarness\Laravel\Artifacts\Artifact;
use AgentHarness\Laravel\Runtime\RunContext;

public function handle(Request $request): Stringable|string
{
    $pdf = Pdf::loadView('reports.weekly', ['data' => $this->gather()])->output();

    $artifact = RunContext::current()->artifacts()->add(
        Artifact::fromContents($pdf, "reports/{$this->team->id}/".now()->toDateString().'.pdf')
            ->name('Weekly performance report')
            ->mimeType('application/pdf')
            ->metadata(['week_of' => now()->startOfWeek()->toDateString()])
    );

    return "Report ready: {$artifact->id}";
}
```

Then hand it to the customer:

```php
Route::get('/reports/{artifact}', function (Request $request, string $artifactId) {
    // The packaged route already authorizes and prefers a temporary URL.
    return redirect("/api/agent-harness/artifacts/{$artifactId}");
});
```

Keep the schedule healthy — these are registered for you, but worth knowing they exist:

```
agent-harness:reap               every five minutes   recovers runs whose worker vanished
agent-harness:expire-approvals   every five minutes   closes stale approval windows
agent-harness:prune              daily at 03:10       applies retention windows
```

---

## 7. A structured grading agent

```php
class RubricAgent implements Agent, HasStructuredOutput, RemembersConversationsContract
{
    use Promptable;
    use RemembersConversations;

    public function instructions(): Stringable|string
    {
        return 'You grade drafts against our content rubric. Be strict and specific.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'score' => $schema->integer()->description('0-100.')->required(),
            'strengths' => $schema->array()->items($schema->string())->required(),
            'fixes' => $schema->array()->items($schema->string())->required(),
        ];
    }
}
```

```php
$result = $session->prompt("Grade this draft:\n\n{$draft}");

$draft->update([
    'score' => $result->structured['score'],
    'feedback' => $result->structured['fixes'],
]);
```

The validated structured value is stored on the terminal run record and emitted with `run.completed`, so it is queryable later without re-running anything:

```php
Run::query()
    ->where('session_id', $session->id)
    ->terminal()
    ->get()
    ->map(fn (Run $run) => $run->structured_output['score'] ?? null);
```

> Structured agents take Laravel AI's buffered prompt path, because that is where validated structured output is produced. Events are synthesized from the completed response rather than streamed token by token.

---

## 8. Testing the whole thing

```php
use AgentHarness\Laravel\Artifacts\Artifact;
use AgentHarness\Laravel\Facades\Harness;
use AgentHarness\Laravel\Runtime\HarnessResult;

it('drafts a post and waits for a human before publishing', function () {
    $fake = Harness::fake([
        HarnessResult::text('Here is the draft.')
            ->withToolCall('search_web', ['q' => 'competitor pricing'], '12 results'),

        HarnessResult::awaitingApproval(
            tool: 'publish_article',
            arguments: ['article_id' => 42],
            reason: 'Publishing is irreversible.',
        ),
    ]);

    $user = User::factory()->create();

    $session = Harness::agent(ContentAgent::class)->for($user)->create();

    $session->queue('Research AI receptionists and draft a post.');
    $session->refresh()->queue('Looks good, publish it.');

    $fake->assertApprovalRequested('publish_article');

    // The tool has not run: nothing was published.
    expect(Article::find(42)->published_at)->toBeNull();
});

it('publishes once the approval lands, and only once', function () {
    $fake = Harness::fake([
        HarnessResult::awaitingApproval(tool: 'publish_article', arguments: ['article_id' => 42]),
        HarnessResult::text('Published.'),
    ]);

    $user = User::factory()->create();
    $session = Harness::agent(ContentAgent::class)->for($user)->create();

    $paused = $session->prompt('Publish article 42.');
    $approval = $paused->pendingApprovals->first();

    // A second request, in a different process, with no memory of the first.
    $this->actingAs($user)
        ->post("/api/agent-harness/runs/{$paused->run->id}/approvals/{$approval->id}/approve")
        ->assertOk();

    // Double-click.
    $this->actingAs($user)
        ->post("/api/agent-harness/runs/{$paused->run->id}/approvals/{$approval->id}/approve")
        ->assertOk();

    $fake->assertApproved('publish_article');
    $fake->assertRunCompleted();

    expect($paused->run->refresh()->events()->where('type', 'approval.resolved')->count())->toBe(1);
});
```

`Harness::fake()` swaps in a deterministic driver and runs queued work inline. Everything else is real: the coordinator, the state machine, the event store, approvals, artifacts, the ledger, and the HTTP routes. You are testing your application against the actual runtime, minus the provider.

### Testing against real Laravel AI

To exercise the real driver, fake the *agent* instead of the harness — Laravel AI's own fake gateway:

```php
it('runs the real driver', function () {
    ResearchAgent::fake(['Their weakest flank is onboarding.']);

    $session = Harness::agent(ResearchAgent::class)->for($this->user)->create();

    expect($session->prompt('Research competitors.')->text)
        ->toBe('Their weakest flank is onboarding.');
});
```

This is the more faithful test: real event translation, real conversation persistence, real approval mapping — only the model call is faked.
