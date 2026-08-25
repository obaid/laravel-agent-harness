<?php

declare(strict_types=1);

namespace Clutch\Laravel\Tools;

use Clutch\Laravel\Runtime\RunContext;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Approvable;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Laravel\Ai\Tools\ToolNameResolver;
use Stringable;

/**
 * Puts one tool behind the ledger, the guards and the spill policy.
 *
 * Laravel AI executes tools inside its own generation loop, so there is no hook
 * to intercept a call once it is under way. Wrapping the tool is the hook: the
 * decorator is what every protection in this package actually hangs off, and
 * without it the ledger is a table nothing writes to.
 *
 * The wrapper is transparent. Laravel AI resolves a tool's name through
 * `name()` before falling back to the class, so the inner tool keeps its
 * identity, its description and its schema.
 */
class GuardedTool implements Tool
{
    public function __construct(
        protected Tool $tool,
        protected ToolExecutionLedger $ledger,
    ) {}

    /**
     * Wrap a tool, keeping it approvable if it already was.
     *
     * Laravel AI checks `instanceof Approvable` to decide whether a call pauses,
     * so a wrapper that dropped the interface would silently disable approvals.
     */
    public static function wrap(Tool $tool, ToolExecutionLedger $ledger): Tool
    {
        return $tool instanceof Approvable
            ? new GuardedApprovableTool($tool, $ledger)
            : new self($tool, $ledger);
    }

    /**
     * The tool this wraps, for anything that needs the real class.
     */
    public function inner(): Tool
    {
        return $this->tool;
    }

    /**
     * Keep the inner tool's name, so the model sees no wrapper.
     */
    public function name(): string
    {
        return ToolNameResolver::resolve($this->tool);
    }

    public function description(): Stringable|string
    {
        return $this->tool->description();
    }

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return $this->tool->schema($schema);
    }

    public function handle(Request $request): Stringable|string
    {
        $context = RunContext::current();

        // Prompted directly through Laravel AI rather than inside a run, so
        // there is no ledger to write to and nothing to protect against.
        if (! $context instanceof RunContext) {
            return $this->tool->handle($request);
        }

        $invocation = $context->invocationFor(
            $this->name(),
            $request->toolCallId() ?? 'call_'.substr(md5(serialize($request->toArray())), 0, 16),
            $request->toArray(),
        );

        // The ledger applies the loop guard and the deadline, then records the
        // side effect so a retry returns the stored result rather than firing
        // it a second time.
        $result = $this->ledger->guard(
            $invocation,
            $this->tool,
            fn (): string => (string) $this->tool->handle($request),
        );

        // A result too large to put in front of the model goes to an artifact,
        // and the model gets a bounded preview and the identifier.
        return $this->ledger->spillIfOversized($invocation, (string) $result, $context->artifacts());
    }
}
