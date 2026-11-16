<?php

declare(strict_types=1);

namespace Mercurius\Messenger\Test\Support;

use Mercurius\Messenger\IncomingMessage;
use Mercurius\Messenger\MessageHandler;

use function Amp\delay;

/**
 * Records handled messages; optional per-text delays and failures simulate slow or broken handlers.
 */
final class RecordingHandler implements MessageHandler
{
    /** @var list<IncomingMessage> */
    public array $handled = [];

    /** @var list<string> "start:<text>" / "end:<text>" in the order they happened */
    public array $events = [];

    /**
     * @param array<string, float> $delays text => seconds to spend in the handler
     * @param list<string> $failOn texts for which the handler throws
     */
    public function __construct(private readonly array $delays = [], private readonly array $failOn = [])
    {
    }

    public function handle(IncomingMessage $message): void
    {
        $this->events[] = 'start:' . $message->text;
        if (isset($this->delays[$message->text])) {
            delay($this->delays[$message->text]);
        }
        if (\in_array($message->text, $this->failOn, true)) {
            $this->events[] = 'fail:' . $message->text;

            throw new \RuntimeException('handler failure');
        }
        $this->handled[] = $message;
        $this->events[] = 'end:' . $message->text;
    }

    /**
     * @return list<string>
     */
    public function texts(): array
    {
        return \array_map(static fn (IncomingMessage $m): string => $m->text, $this->handled);
    }

    /**
     * @param array<string, mixed> $override
     * @return array<string, mixed>
     */
    public static function update(int $updateId, string $chatId, string $text, array $override = []): array
    {
        return [
            'update_id' => $updateId,
            'message' => \array_merge([
                'message_id' => $updateId * 10,
                'date' => 1_763_280_000,
                'chat' => ['id' => (int) $chatId, 'type' => 'private'],
                'from' => ['id' => (int) $chatId, 'is_bot' => false, 'first_name' => 'User'],
                'text' => $text,
            ], $override),
        ];
    }
}
