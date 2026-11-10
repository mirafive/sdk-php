<?php

declare(strict_types=1);

use MiraFive\Http\TransportException;
use MiraFive\Mira;
use MiraFive\MiraError;
use MiraFive\Testing\RecordingTransport;
use Psr\SimpleCache\CacheInterface;

beforeEach(function (): void {
    $this->transport = new RecordingTransport;
    $this->errors = [];
    $this->waits = [];
    $this->mira = client($this->transport, $this->errors, ['sleep' => function (int $ms): void {
        $this->waits[] = $ms;
    }]);
});

it('sends at most flushAt events per batch, with seq from 1 on one stream', function (): void {
    $mira = client($this->transport, $this->errors, ['flushAt' => 2]);

    foreach (range(1, 5) as $i) {
        $mira->track("e{$i}");
    }

    // Two full batches left on their own; the last event waits for flush().
    expect($this->transport->requests())->toHaveCount(2);
    $mira->flush();

    $batches = $this->transport->batches();

    expect(array_map(static fn (array $batch): int => count($batch['events']), $batches))->toBe([2, 2, 1])
        ->and(array_column($batches, 'seq'))->toBe([1, 2, 3])
        ->and(array_unique(array_column($batches, 'stream')))->toHaveCount(1)
        ->and($batches[0]['stream'])->toMatch('/^[0-9a-f-]{36}$/');
});

it('clamps flushAt to the ingest limit of 100', function (): void {
    $mira = client($this->transport, $this->errors, ['flushAt' => 500]);

    foreach (range(1, 150) as $i) {
        $mira->track('e');
    }

    $mira->flush();

    expect(array_map(static fn (array $batch): int => count($batch['events']), $this->transport->batches()))->toBe([100, 50]);
});

it('splits batches that would pass 500 kB', function (): void {
    $big = ['blob' => str_repeat('x', 2000)];
    $properties = array_fill_keys(array_map(static fn (int $i): string => "p{$i}", range(1, 60)), $big['blob']);

    foreach (range(1, 10) as $i) {
        $this->mira->track('big', properties: $properties);
    }

    $this->mira->flush();

    $requests = $this->transport->requests();

    expect(count($requests))->toBeGreaterThan(1)
        ->and(max(array_map(static fn (array $request): int => strlen($request['body']), $requests)))->toBeLessThan(512 * 1024)
        ->and(array_sum(array_map(static fn (array $request): int => count($request['batch']['events']), $requests)))->toBe(10);
});

it('retries network errors, 408, 429 and 5xx with the same events and seq', function (): void {
    $this->transport->answerNext(new TransportException('connection refused'), answer(503));
    $this->mira->track('a');
    $this->mira->flush();

    $requests = $this->transport->requests();

    expect($requests)->toHaveCount(3)
        ->and(array_unique(array_map(static fn (array $request): string => json_encode([$request['batch']['seq'], $request['batch']['events']]) ?: '', $requests)))->toHaveCount(1)
        ->and($this->errors)->toBe([])
        ->and($this->waits)->toHaveCount(2)
        ->and(max($this->waits))->toBeLessThanOrEqual(200);
});

it('gives up after maxRetries and reports the event ids', function (): void {
    $this->transport->answerNext(answer(500), answer(502), answer(500, ['error' => 'internal']));
    $this->mira->track('a', eventId: Mira::eventIdFor('a'));
    $this->mira->flush();

    expect($this->transport->requests())->toHaveCount(3)
        ->and($this->errors)->toHaveCount(1)
        ->and($this->errors[0]->getMessage())->toBe('batch dropped after 3 attempts: batch dropped: HTTP 500 internal')
        ->and($this->errors[0]->status)->toBe(500)
        ->and($this->errors[0]->retryable)->toBeTrue()
        ->and($this->errors[0]->eventIds)->toBe([Mira::eventIdFor('a')]);
});

it('waits as long as Retry-After asks', function (): void {
    $this->transport->answerNext(answer(429, ['error' => 'rate_limited'], ['Retry-After' => '1']));
    $this->mira->track('a');
    $this->mira->flush();

    expect($this->waits)->toBe([1000])
        ->and($this->transport->batches())->toHaveCount(1);
});

it('does not wait longer than maxRetryAfterMs inside a request', function (): void {
    $this->transport->answerNext(answer(429, ['error' => 'rate_limited'], ['Retry-After' => '10']));
    $this->mira->track('a');
    $this->mira->flush();

    expect($this->waits)->toBe([])
        ->and($this->errors[0]->getMessage())->toBe('batch dropped after 1 attempt: batch dropped: HTTP 429 rate_limited')
        ->and($this->errors[0]->retryAfterMs)->toBe(10_000);
});

it('treats other 4xx answers as final', function (int $status, string $error, string $message): void {
    $this->transport->answerNext(answer($status, ['error' => $error]));
    $this->mira->track('a');
    $this->mira->flush();

    expect($this->transport->requests())->toHaveCount(1)
        ->and($this->errors[0]->getMessage())->toBe($message)
        ->and($this->errors[0]->retryable)->toBeFalse()
        ->and($this->errors[0]->reason)->toBe($error);
})->with([
    'bad key' => [401, 'unknown_key', 'batch dropped: HTTP 401 unknown_key: check MIRAFIVE_SECRET_KEY, it must be a secret key (mira_sk_…) of this project'],
    'bad batch' => [400, 'invalid_batch', 'batch dropped: HTTP 400 invalid_batch'],
    'too large' => [413, 'payload_too_large', 'batch dropped: HTTP 413 payload_too_large: the batch is larger than 512 KiB'],
]);

it('reports the events the ingest rejected, with their ids', function (): void {
    $this->transport->answerNext(answer(202, ['accepted' => 1, 'duplicates' => 0, 'rejected' => [['index' => 1, 'reason' => 'forbidden: $revenue needs a secret key']]]));
    $this->mira->track('a', eventId: Mira::eventIdFor('a'));
    $this->mira->track('b', eventId: Mira::eventIdFor('b'));
    $this->mira->flush();

    expect(messages($this->errors))->toBe(['1 event(s) rejected: forbidden: $revenue needs a secret key'])
        ->and($this->errors[0]->eventIds)->toBe([Mira::eventIdFor('b')]);
});

it('stops trying the network for a while after a delivery failed, and says so once', function (): void {
    $this->transport->answerNext(new TransportException('down'), new TransportException('down'), new TransportException('down'));
    $this->mira->track('a');
    $this->mira->flush();
    $this->mira->track('b');
    $this->mira->flush();
    $this->mira->track('c');
    $this->mira->flush();

    expect($this->transport->requests())->toHaveCount(3)
        ->and(messages($this->errors))->toBe([
            'batch dropped after 3 attempts: network error: down',
            'delivery failed recently; events are dropped without trying the network for up to 30 s',
        ]);
});

it('shares the breaker through a PSR-16 cache', function (): void {
    $cache = new class implements CacheInterface
    {
        /** @var array<string, mixed> */
        public array $values = [];

        public function get(string $key, mixed $default = null): mixed
        {
            return $this->values[$key] ?? $default;
        }

        public function set(string $key, mixed $value, DateInterval|int|null $ttl = null): bool
        {
            $this->values[$key] = $value;

            return true;
        }

        public function delete(string $key): bool
        {
            unset($this->values[$key]);

            return true;
        }

        public function clear(): bool
        {
            return true;
        }

        public function getMultiple(iterable $keys, mixed $default = null): iterable
        {
            return [];
        }

        public function setMultiple(iterable $values, DateInterval|int|null $ttl = null): bool
        {
            return true;
        }

        public function deleteMultiple(iterable $keys): bool
        {
            return true;
        }

        public function has(string $key): bool
        {
            return isset($this->values[$key]);
        }
    };

    $first = client($this->transport, $this->errors, ['cache' => $cache, 'maxRetries' => 0]);
    $this->transport->answerNext(answer(503));
    $first->track('a');
    $first->flush();

    $second = client($this->transport, $this->errors, ['cache' => $cache]);
    $second->track('b');
    $second->flush();

    expect($this->transport->requests())->toHaveCount(1)
        ->and(array_keys($cache->values)[0])->toMatch('/^mirafive\.[0-9a-f]{12}\.breaker$/');
});

it('keeps a flush within its deadline', function (): void {
    $mira = client($this->transport, $this->errors, ['flushDeadlineMs' => 250, 'maxRetries' => 10, 'sleep' => function (int $ms): void {
        $this->waits[] = $ms;
    }]);
    $this->transport->answerNext(...array_fill(0, 20, answer(503)));
    $mira->track('a');
    $mira->flush();

    expect(array_sum($this->waits))->toBeLessThan(250)
        ->and($this->errors)->toHaveCount(1)
        ->and($this->errors[0]->retryable)->toBeTrue();
});

it('hands batches off instead of sending them, and a worker delivers them', function (): void {
    $payloads = [];
    $producer = client($this->transport, $this->errors, ['secretKey' => '', 'flushAt' => 2, 'handOff' => function (string $payload) use (&$payloads): void {
        $payloads[] = $payload;
    }]);

    $producer->track('a');
    $producer->track('b');
    $producer->track('c');
    $producer->flush();

    expect($this->transport->requests())->toBe([])
        ->and($payloads)->toHaveCount(2)
        ->and($payloads[0])->not->toContain('mira_sk_')
        ->and($payloads[0])->not->toContain('sent_at')
        ->and(json_decode($payloads[1], true))->toMatchArray(['seq' => 2]);

    $worker = client($this->transport, $this->errors);
    $result = $worker->deliverPrepared($payloads[0]);
    $worker->deliverPrepared($payloads[0]);

    $requests = $this->transport->requests();

    expect($result->accepted)->toBe(2)
        ->and($requests[0]['headers']['Authorization'])->toBe('Bearer mira_sk_test')
        ->and($requests[0]['batch']['events'])->toBe($requests[1]['batch']['events'])
        ->and($requests[0]['batch']['seq'])->toBe(1)
        ->and($requests[0]['body'])->toStartWith('{"sent_at":"')
        ->and(substr($requests[0]['body'], strpos($requests[0]['body'], '"stream"') ?: 0))->toBe(substr($payloads[0], 1));
});

it('throws from deliverPrepared so a queue can retry', function (): void {
    $this->transport->answerNext(answer(503), answer(503), answer(503));

    $this->mira->deliverPrepared('{"stream":"s","seq":1,"events":[{"event_id":"x"}]}');
})->throws(MiraError::class, 'batch dropped after 3 attempts');

it('refuses a payload that is not a prepared batch', function (string $payload): void {
    $this->mira->deliverPrepared($payload);
})->throws(MiraError::class, 'Not a prepared MIRA FIVE batch')->with([
    'not json' => ['nope'],
    'no events' => ['{"stream":"s","seq":1,"events":[]}'],
    'a request body' => ['{"sent_at":"2026-11-08T00:00:00Z","stream":"s","seq":1,"events":[{}]}'],
]);

it('reports a hand-off that throws', function (): void {
    $mira = client($this->transport, $this->errors, ['handOff' => static function (): void {
        throw new RuntimeException('queue down');
    }]);
    $mira->track('a');
    $mira->flush();

    expect(messages($this->errors))->toBe(['the hand-off failed: queue down'])
        ->and($this->errors[0]->eventIds)->toHaveCount(1);
});

it('checks the connection with GET /v1/check, which sends no event', function (): void {
    $this->transport->answerNext(answer(200, ['ok' => true, 'key' => 'secret']));

    expect($this->mira->checkConnection())->toBe('secret');

    $request = $this->transport->requests()[0];

    expect($request['method'])->toBe('GET')
        ->and($request['url'])->toEndWith('/v1/check')
        ->and($request['body'])->toBe('')
        ->and($this->errors)->toBe([]);
});

it('fails the connection check for a bad key or a host that is no ingest', function (): void {
    $this->transport->answerNext(answer(401, ['error' => 'unknown_key']));
    expect(fn () => $this->mira->checkConnection())->toThrow(MiraError::class, 'HTTP 401 unknown_key');

    $this->transport->answerNext(answer(200, ['hello' => 'world']));
    expect(fn () => $this->mira->checkConnection())->toThrow(MiraError::class, 'but not like a MIRA FIVE ingest');
});

it('flushes when the client is destroyed', function (): void {
    $mira = client($this->transport, $this->errors);
    $mira->track('a');
    unset($mira);

    expect($this->transport->requests())->toHaveCount(1);
});

it('keeps the buffer shared between a client and its scopes', function (): void {
    $this->mira->with(userId: 'u')->track('a');
    $this->mira->track('b');

    expect($this->transport->requests())->toBe([]);

    $this->mira->flush();

    expect($this->transport->requests())->toHaveCount(1);
});

it('erases a person with DELETE /v1/persons/:userId and returns the proof', function (): void {
    $this->transport->answerNext(answer(200, ['request_id' => 'req-1', 'deleted' => ['events' => 2, 'persons' => 1], 'revenue_kept' => 1, 'completed_at' => '2026-11-10T12:00:00.000Z']));

    $proof = $this->mira->deletePerson(' a/b@c.de ');
    $request = $this->transport->requests()[0];

    expect($proof->requestId)->toBe('req-1')
        ->and($proof->deleted)->toBe(['events' => 2, 'persons' => 1])
        ->and($proof->revenueKept)->toBe(1)
        ->and($request['method'])->toBe('DELETE')
        ->and($request['url'])->toBe('https://ingest.test/v1/persons/%20a%2Fb%40c.de%20')
        ->and($request['headers']['Authorization'])->toBe('Bearer mira_sk_test')
        ->and($request['body'])->toBe('')
        ->and($this->errors)->toBe([]);
});

it('retries a busy or failing ingest when erasing, then succeeds', function (): void {
    $this->transport->answerNext(
        new TransportException('connection reset'),
        answer(503, ['error' => 'erasure_failed']),
        answer(200, ['request_id' => 'req-2', 'deleted' => [], 'revenue_kept' => 0, 'completed_at' => '']),
    );

    expect($this->mira->deletePerson('u1')->requestId)->toBe('req-2')
        ->and($this->transport->requests())->toHaveCount(3);
});

it('throws a retryable error once the erasure retries are used up', function (): void {
    $this->transport->answerNext(answer(429, ['error' => 'rate_limited']), answer(429, ['error' => 'rate_limited']), answer(429, ['error' => 'rate_limited']));

    try {
        $this->mira->deletePerson('u1');
        $this->fail('expected a MiraError');
    } catch (MiraError $error) {
        expect($error->status)->toBe(429)
            ->and($error->reason)->toBe('rate_limited')
            ->and($error->retryable)->toBeTrue();
    }

    expect($this->transport->requests())->toHaveCount(3);
});

it('maps a refused key or id to errors that do not retry', function (int $status, string $reason): void {
    $this->transport->answerNext(answer($status, ['error' => $reason]));

    try {
        $this->mira->deletePerson('u1');
        $this->fail('expected a MiraError');
    } catch (MiraError $error) {
        expect($error->status)->toBe($status)
            ->and($error->reason)->toBe($reason)
            ->and($error->retryable)->toBeFalse()
            ->and($error->getMessage())->toContain($reason);
    }

    expect($this->transport->requests())->toHaveCount(1);
})->with([[401, 'unknown_key'], [403, 'secret_key_required'], [400, 'invalid_user_id']]);

it('does not take an answer that is no erasure proof for a success', function (): void {
    $this->transport->answerNext(answer(200, ['ok' => true]));

    expect(fn () => $this->mira->deletePerson('u1'))->toThrow(MiraError::class, 'not like a MIRA FIVE ingest');
});

it('keeps the user id out of erasure error messages', function (): void {
    $this->transport->answerNext(new TransportException('boom'), new TransportException('boom'), new TransportException('boom'));

    try {
        $this->mira->deletePerson('ada@example.com');
        $this->fail('expected a MiraError');
    } catch (MiraError $error) {
        expect($error->getMessage())->not->toContain('ada@example.com')
            ->and($error->getMessage())->not->toContain('ada%40example.com');
    }
});

it('refuses a bad id, a public key and a disabled client without calling the ingest', function (): void {
    foreach (['', str_repeat('x', 201), "bad\xB1utf8"] as $id) {
        expect(fn () => $this->mira->deletePerson($id))->toThrow(MiraError::class);
    }

    $public = new Mira(secretKey: 'mira_pk_test', host: 'https://ingest.test', onError: static fn () => null, transport: $this->transport, flushOnShutdown: false);
    $disabled = new Mira(secretKey: 'mira_sk_test', host: 'https://ingest.test', enabled: false, transport: $this->transport, flushOnShutdown: false);

    expect(fn () => $public->deletePerson('u1'))->toThrow(MiraError::class, 'secret key')
        ->and(fn () => $disabled->deletePerson('u1'))->toThrow(MiraError::class, 'secret key')
        ->and($this->transport->requests())->toBe([]);
});
