<?php

declare(strict_types=1);

namespace Mercurius\Messenger\Test\Support;

use Amp\Cancellation;
use Amp\DeferredFuture;
use Amp\Http\Client\DelegateHttpClient;
use Amp\Http\Client\HttpClient;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;
use Amp\Http\Client\SocketException;

use function Amp\ByteStream\buffer;

/**
 * In-memory stand-in for the Bot API: records calls and answers with canned responses, no network.
 *
 * Responses are queued per method. Without a queued response `sendMessage` returns a new message id, other
 * methods return `true`, and `getUpdates` blocks until cancelled (an idle long poll).
 */
final class FakeTelegramServer implements DelegateHttpClient
{
    public const TOKEN = '123456:TEST-token_abc';

    /** @var list<array{method: string, params: array<array-key, mixed>, url: string, time: float}> */
    public array $calls = [];

    /** @var array<string, list<array{int, string}|\Closure(array<array-key, mixed>): array{int, string}>> */
    private array $queue = [];

    private int $nextMessageId = 100;

    /** @var list<DeferredFuture<mixed>> */
    private array $waiters = [];

    public function client(): HttpClient
    {
        return new HttpClient($this, []);
    }

    /**
     * Queues a raw response (HTTP status and body) for the next call of a method.
     */
    public function respond(string $method, int $status, string $body): void
    {
        $this->queue[$method][] = [$status, $body];
    }

    public function respondOk(string $method, mixed $result = true): void
    {
        $this->respond($method, 200, \json_encode(['ok' => true, 'result' => $result], \JSON_THROW_ON_ERROR));
    }

    /**
     * @param array<string, mixed> $parameters
     */
    public function respondError(string $method, int $code, string $description, array $parameters = []): void
    {
        $body = ['ok' => false, 'error_code' => $code, 'description' => $description];
        if ($parameters !== []) {
            $body['parameters'] = $parameters;
        }
        $this->respond($method, $code, \json_encode($body, \JSON_THROW_ON_ERROR));
    }

    /**
     * Queues an error of any kind for the next call of a method: a failure the API client does not know.
     */
    public function failWith(string $method, \Throwable $error): void
    {
        $this->queue[$method][] = static function () use ($error): array {
            throw $error;
        };
    }

    /**
     * Queues a network failure for the next call of a method.
     */
    public function failNetwork(string $method): void
    {
        $this->queue[$method][] = static function (): array {
            throw new SocketException('Connection to api.telegram.org failed');
        };
    }

    /**
     * Calls of one method in order.
     *
     * @return list<array<array-key, mixed>>
     */
    public function params(string $method): array
    {
        $result = [];
        foreach ($this->calls as $call) {
            if ($call['method'] === $method) {
                $result[] = $call['params'];
            }
        }

        return $result;
    }

    /**
     * Time when the message with this text was sent.
     */
    public function sentAt(string $text): float
    {
        foreach ($this->calls as $call) {
            if ($call['method'] === 'sendMessage' && ($call['params']['text'] ?? null) === $text) {
                return $call['time'];
            }
        }

        throw new \LogicException("No message with text {$text} was sent");
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function arrayOrEmpty(mixed $value): array
    {
        return \is_array($value) ? $value : [];
    }

    /**
     * Resolves once the server has received at least `$count` calls of `$method`.
     */
    public function waitForCalls(string $method, int $count): void
    {
        while (\count($this->params($method)) < $count) {
            $deferred = new DeferredFuture();
            $this->waiters[] = $deferred;
            $deferred->getFuture()->await();
        }
    }

    public function request(Request $request, Cancellation $cancellation): Response
    {
        $url = (string) $request->getUri();
        $method = \substr($url, (int) \strrpos($url, '/') + 1);
        $body = buffer($request->getBody()->getContent());
        // Uploads are multipart: keep the raw body, the tests only count them.
        $params = \str_starts_with($request->getBody()->getContentType() ?? '', 'multipart/')
            ? ['multipart' => $body]
            : self::arrayOrEmpty(\json_decode($body === '' ? '{}' : $body, true, 512, \JSON_THROW_ON_ERROR));
        $this->calls[] = [
            'method' => $method,
            'params' => $params,
            'url' => $url,
            'time' => \microtime(true),
        ];
        $waiters = $this->waiters;
        $this->waiters = [];
        foreach ($waiters as $waiter) {
            $waiter->complete();
        }

        $next = isset($this->queue[$method]) ? \array_shift($this->queue[$method]) : null;
        if ($next instanceof \Closure) {
            $next = $next($params);
        }

        if ($next === null) {
            $next = match ($method) {
                'sendMessage' => [200, \json_encode(
                    ['ok' => true, 'result' => ['message_id' => $this->nextMessageId++]],
                    \JSON_THROW_ON_ERROR,
                )],
                'getUpdates' => $this->idlePoll($cancellation),
                default => [200, '{"ok":true,"result":true}'],
            };
        }

        return new Response('1.1', $next[0], null, ['content-type' => 'application/json'], $next[1], $request);
    }

    /**
     * @return array{int, string}
     */
    private function idlePoll(Cancellation $cancellation): array
    {
        /** @var DeferredFuture<null> $deferred */
        $deferred = new DeferredFuture();
        $id = $cancellation->subscribe(static fn (\Throwable $e) => $deferred->error($e));
        try {
            $deferred->getFuture()->await();
        } finally {
            $cancellation->unsubscribe($id);
        }

        return [200, '{"ok":true,"result":[]}'];
    }
}
