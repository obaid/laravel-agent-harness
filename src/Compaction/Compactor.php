<?php

declare(strict_types=1);

namespace Clutch\Laravel\Compaction;

use Clutch\Laravel\Enums\EventType;
use Clutch\Laravel\Models\Run;
use Clutch\Laravel\Models\Session;
use Clutch\Laravel\Runtime\EventStore;
use Illuminate\Support\Collection;
use Laravel\Ai\Agents\SummarizeAgent;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\UserMessage;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Replaces the middle of a conversation with a summary of it.
 *
 * The summary is produced by Laravel AI's own SummarizeAgent, which is marked
 * to use the cheapest model available, so compaction does not cost more than
 * the context it saves.
 *
 * Compaction is recorded as an event with the token counts either side of it,
 * because silently rewriting a conversation is the kind of thing an operator
 * needs to be able to see afterwards.
 */
class Compactor
{
    public function __construct(
        protected CompactionPolicy $policy,
        protected ConversationStore $conversations,
        protected EventStore $events,
        protected LoggerInterface $logger,
    ) {}

    /**
     * Compact a session's conversation if the policy calls for it.
     *
     * Returns the summary that replaced the middle, or null when nothing was
     * done. A failure here is never fatal: a run that could not be compacted
     * still runs, it just carries more context than we would like.
     */
    public function compact(Session $session, Run $run, int $maxMessages = 100): ?string
    {
        if (! $this->policy->isEnabled() || $session->conversation_id === null) {
            return null;
        }

        try {
            $messages = $this->conversations->getLatestConversationMessages(
                $session->conversation_id,
                $maxMessages,
            );

            $partition = $this->policy->partition($messages->all());

            if ($partition['middle'] === []) {
                return null;
            }

            $summary = $this->summarize($partition['middle']);

            if ($summary === null) {
                return null;
            }

            $this->events->append($run, EventType::CompactionApplied, [
                'conversation_id' => $session->conversation_id,
                'messages_summarized' => count($partition['middle']),
                'messages_kept' => count($partition['head']) + count($partition['tail']),
                'summary_characters' => strlen($summary),
            ]);

            return $summary;
        } catch (Throwable $e) {
            // Compaction is an optimization. Losing it costs context, not
            // correctness, so the run continues uncompacted.
            $this->logger->warning('Clutch could not compact a conversation.', [
                'session_id' => $session->id,
                'run_id' => $run->id,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Summarize a stretch of conversation into a few sentences.
     *
     * @param  array<int, Message>  $messages
     */
    protected function summarize(array $messages): ?string
    {
        $transcript = (new Collection($messages))
            ->map(fn (Message $message): string => $this->render($message))
            ->filter()
            ->implode("\n\n");

        if (trim($transcript) === '') {
            return null;
        }

        $response = (new SummarizeAgent($this->policy->summarySentences()))
            ->prompt("Summarize this portion of an agent conversation:\n\n".$transcript);

        return trim($response->text) === '' ? null : trim($response->text);
    }

    /**
     * Render one message as plain text for the summarizer.
     */
    protected function render(Message $message): string
    {
        $role = $message instanceof UserMessage ? 'User' : 'Assistant';

        $content = trim((string) $message->content);

        return $content === '' ? '' : "{$role}: {$content}";
    }
}
