# DR;SA

Didn't read, sent to agent.

You have a Laravel app, you have a coding agent, and you would rather not read a 1,000 line guide to find out whether this package is worth installing. Copy one of the blocks below into Claude Code, Codex, Cursor, or whatever you use, and let it do the reading.

Each prompt points the agent at [llms.txt](https://obaid.github.io/laravel-clutch/llms.txt), a short index written for machines, which links to the full guide. That is deliberate. An agent that has read the real documentation writes real code, and an agent that has not invents a plausible API that does not exist.

## Install it

The whole thing, from nothing to a session you can prompt.

```text
Add Laravel Clutch to this application.

Clutch is a durable agent harness for Laravel. It wraps the official laravel/ai
SDK rather than replacing it. Start by reading
https://obaid.github.io/laravel-clutch/llms.txt and follow the guide it links
to. Do not guess at the API surface.

Requirements: PHP 8.3+, Laravel 12 or 13, laravel/ai 0.11.x.

1. composer require obaid/laravel-clutch

2. Publish and migrate. Both providers, not just the first:

     php artisan vendor:publish --provider="Clutch\Laravel\ClutchServiceProvider"
     php artisan vendor:publish --provider="Laravel\Ai\AiServiceProvider"
     php artisan migrate

   Laravel AI's conversation tables are not optional. Session context lives in
   them, and sessions cannot continue across requests without them.

3. Give agent work its own queue. Set CLUTCH_QUEUE_CONNECTION and CLUTCH_QUEUE
   in .env, and tell me the worker command to run. A four minute run should not
   sit in front of password reset emails.

4. Find the agent in this codebase that most needs to outlive a request. If
   there isn't one, generate it with `php artisan make:clutch-agent`. Either
   way it must:

     - use Laravel\Ai\Concerns\RemembersConversations
     - implement Laravel\Ai\Contracts\RemembersConversations
     - return its tools through Clutch::policy([...]), never directly

   Both matter and they fail differently. Without the trait, Clutch refuses to
   create a session and tells you so. Without Clutch::policy(), approvals,
   idempotency, loop guards and output spilling silently never run.

5. Replace whatever calls $agent->prompt(...) with a session:

     $session = Clutch::agent(TheAgent::class)->for($request->user())->create();
     $result  = $session->prompt($input);   // inline, returns when done
     $run     = $session->queue($input);    // background, returns immediately

6. Classify the tools. A tool whose effect cannot be undone should implement
   Laravel\Ai\Contracts\Approvable and use InteractsWithApprovals. A tool that
   touches an external system should implement
   Clutch\Laravel\Contracts\IdempotentTool and key the side effect rather than
   the call, so two retries of the same action collapse into one.

Then show me the diff, the worker command, and one curl that starts a run.
Do not build a UI unless I ask for one.
```

## Move an agent you already have onto it

For an app already using Laravel AI, where the agent works and the problem is everything around it.

```text
This app already uses laravel/ai. Move <AgentClass> onto Laravel Clutch so its
runs survive the request that started them.

Read https://obaid.github.io/laravel-clutch/llms.txt first.

Keep the agent's instructions and tools exactly as they are. What changes is
the lifecycle around them:

  - install the package and publish both sets of migrations
  - add the RemembersConversations trait and contract if they are missing
  - wrap the tools in Clutch::policy([...])
  - swap the direct prompt call for a Clutch session
  - move anything slow to $session->queue(...) and give me the worker command

Tell me which call sites you changed and which you left alone, and why.
```

## Make one tool ask permission

The smallest useful change. Worth doing on its own before committing to the rest.

```text
<ToolClass> in this app does something that cannot be undone. Using Laravel
Clutch, make it stop and ask a human first, and make the answer survive a
deploy.

Read https://obaid.github.io/laravel-clutch/llms.txt for the API.

  - the tool implements Laravel\Ai\Contracts\Approvable and uses
    InteractsWithApprovals
  - the agent returns it through Clutch::policy([...]), or none of this runs
  - the run pauses, writes a checkpoint, and the worker exits, rather than
    holding a connection open while it waits
  - approving from a different process later resumes the run

Show me the endpoints the package already ships for listing and resolving
approvals, and where to hook a notification. Don't build an approvals UI.
```

## Add a progress UI that survives a refresh

```text
Using Laravel Clutch, add a page that shows a run's progress live and rebuilds
itself correctly when the browser is refreshed mid-run.

Read https://obaid.github.io/laravel-clutch/llms.txt, and the recipe on live
progress UIs that it links to.

The event stream is a route the package already ships. Use it rather than
writing your own. Replay from the cursor first, then go live, so a reload does
not lose the steps that happened while the page was gone.
```

## Check its work

Agents get most of this right and a few things reliably wrong. Four things worth looking at before you trust the diff.

**Are the tools going through `Clutch::policy()`?** This is the one that fails quietly. An agent returning `[new SearchWeb, new SendEmail]` from `tools()` still works, still calls the model, still runs the tools, and gets none of the protection. Nothing errors. Approvals just never fire.

**Did both `vendor:publish` commands run?** Skipping Laravel AI's leaves you with sessions that cannot continue, and the failure shows up later, in a second turn, rather than at install.

**Is there a worker?** Queued runs do nothing at all without one, and the first symptom is a run stuck in `queued` with no error anywhere.

**Did it invent a method?** The fastest check is `php artisan clutch:sessions` and `php artisan clutch:events`, which fail loudly against a real database if the wiring is wrong.

## For your agent

- [llms.txt](https://obaid.github.io/laravel-clutch/llms.txt) is the short index. Point an agent here.
- [llms-full.txt](https://obaid.github.io/laravel-clutch/llms-full.txt) is the guide and every recipe as one plain text file, for pasting into a context window whole.
