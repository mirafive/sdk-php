<?php

declare(strict_types=1);

use MiraFive\BatchId;
use MiraFive\Http\TransportException;
use MiraFive\Mira;
use MiraFive\MiraError;
use MiraFive\Mode;
use MiraFive\Receipt;
use MiraFive\Tests\Support\ArrayCache;
use MiraFive\Tests\Support\FakeTransport;

const KEY = 'mf_ab12cd34_secretTail';

/**
 * @param  list<int>  $slept
 * @param  list<MiraError>  $errors
 */
function client(FakeTransport $transport, array &$slept = [], array &$errors = [], Mode $mode = Mode::Full, int $flushAt = 100, int $maxRetries = 2): Mira
{
    return new Mira(
        key: KEY,
        host: 'https://events.example.test/',
        mode: $mode,
        flushAt: $flushAt,
        maxRetries: $maxRetries,
        transport: $transport,
        onError: function (MiraError $error) use (&$errors): void {
            $errors[] = $error;
        },
        sleep: function (int $ms) use (&$slept): void {
            $slept[] = $ms;
        },
    );
}

it('buffers events until flush and sends them as one protocol batch', function (): void {
    $transport = transport(accepted(2));
    $mira = client($transport);

    $mira->track('signup', userId: 'u_42', properties: ['plan' => 'pro'], time: new DateTimeImmutable('@1727430100.123'));
    $mira->identify('u_42', ['plan' => 'pro'], anonymousId: '5f0c1c8e-3e0e-4a57-9d59-3f7f2a6d1e44');

    expect($transport->requests)->toBeEmpty();

    $mira->flush();
    $request = $transport->requests[0];
    $body = $transport->body(0);

    expect($request['method'])->toBe('POST')
        ->and($request['url'])->toBe('https://events.example.test/v1/batch')
        ->and($request['headers']['Authorization'])->toBe('Bearer '.KEY)
        ->and($request['headers']['Content-Type'])->toBe('application/json')
        ->and($request['timeoutMs'])->toBe(3000)
        ->and($request['connectTimeoutMs'])->toBe(1000)
        ->and($body['v'])->toBe(1)
        ->and($body['batch'])->toMatch('/^[0-9a-f-]{36}$/')
        ->and($body['mode'])->toBe('full')
        ->and($body['sentAt'])->toBeInt()
        ->and($body['context'])->toBe(['sdk' => 'mirafive-php/0.5.0'])
        ->and($body['events'][0])->toBe(['name' => 'signup', 'time' => 1727430100123, 'properties' => ['plan' => 'pro'], 'userId' => 'u_42'])
        ->and($body['events'][1]['name'])->toBe('$identify')
        ->and($body['events'][1]['anonymousId'])->toBe('5f0c1c8e-3e0e-4a57-9d59-3f7f2a6d1e44');

    $mira->flush();

    expect($transport->requests)->toHaveCount(1);
});

it('flushes on its own once flushAt events are buffered', function (): void {
    $transport = transport(accepted(3));
    $mira = client($transport, flushAt: 3);

    $mira->track('a');
    $mira->track('b');

    expect($transport->requests)->toBeEmpty();

    $mira->track('c');

    expect($transport->body(0)['events'])->toHaveCount(3);
});

it('flushes when the client is destroyed', function (): void {
    $transport = transport(accepted());
    $mira = client($transport);
    $mira->track('signup');

    unset($mira);

    expect($transport->requests)->toHaveCount(1);
});

it('sends immediately and returns the receipt', function (): void {
    $transport = transport(answer(202, ['batch' => '274e05f0-4dd7-8db9-a563-87ea5c891487', 'accepted' => 1, 'dropped' => 0]));

    $receipt = client($transport)->send([['name' => 'order completed', 'userId' => 'u_42', 'time' => 1727430199000]], idempotencyKey: 'order-981');

    expect($receipt)->toEqual(new Receipt('274e05f0-4dd7-8db9-a563-87ea5c891487', 1, 0))
        ->and($transport->body(0)['batch'])->toBe(BatchId::fromIdempotencyKey('order-981'))
        ->and($transport->body(0)['events'][0]['time'])->toBe(1727430199000);
});

it('passes a dropped batch through as a final receipt', function (): void {
    $transport = transport(answer(202, ['batch' => 'b', 'accepted' => 0, 'dropped' => 1, 'reason' => 'install_check']));

    expect(client($transport)->send([['name' => '$install_check']]))->toEqual(new Receipt('b', 0, 1, 'install_check'))
        ->and($transport->requests)->toHaveCount(1);
});

it('refuses batches outside 1–1000 events', function (int $count): void {
    client(transport())->send(array_fill(0, $count, ['name' => 'a']));
})->with([0, 1001])->throws(InvalidArgumentException::class);

it('retries 408, 429, 5xx and network errors with the byte-identical body', function (): void {
    $transport = transport(answer(503, ['code' => 'sink_unavailable', 'detail' => 'down']), new TransportException('reset'), accepted());
    $slept = [];

    client($transport, $slept)->send([['name' => 'a']]);

    expect($transport->requests)->toHaveCount(3)
        ->and($transport->requests[1]['body'])->toBe($transport->requests[0]['body'])
        ->and($transport->requests[2]['body'])->toBe($transport->requests[0]['body'])
        ->and($slept)->toHaveCount(2)
        ->and($slept[0])->toBeLessThanOrEqual(100)
        ->and($slept[1])->toBeLessThanOrEqual(200);
});

it('gives up after maxRetries and throws a retryable MiraError', function (): void {
    $transport = transport(answer(500), answer(502), answer(504));

    try {
        client($transport)->send([['name' => 'a']]);
        $this->fail('send() did not throw');
    } catch (MiraError $error) {
        expect($error->errorCode)->toBe('unexpected')
            ->and($error->status)->toBe(504)
            ->and($error->getCode())->toBe(504)
            ->and($error->retryable)->toBeTrue();
    }

    expect($transport->requests)->toHaveCount(3);
});

it('does not retry a refusal and carries the validation errors', function (): void {
    $transport = transport(answer(400, [
        'code' => 'validation_failed',
        'detail' => 'the batch is invalid',
        'errors' => [['path' => 'events.0.name', 'message' => 'too long']],
    ]));

    try {
        client($transport)->send([['name' => 'a']]);
        $this->fail('send() did not throw');
    } catch (MiraError $error) {
        expect($error->errorCode)->toBe('validation_failed')
            ->and($error->retryable)->toBeFalse()
            ->and($error->errors)->toBe([['path' => 'events.0.name', 'message' => 'too long']]);
    }

    expect($transport->requests)->toHaveCount(1);
});

it('waits for Retry-After before retrying a rate limit', function (): void {
    $transport = transport(answer(429, ['code' => 'rate_limited', 'detail' => 'slow down'], ['Retry-After' => '2']), accepted());
    $slept = [];

    client($transport, $slept)->send([['name' => 'a']]);

    expect($slept)->toBe([2000]);
});

it('stops when Retry-After asks for longer than a request can wait', function (): void {
    $transport = transport(answer(429, ['code' => 'rate_limited', 'detail' => 'slow down'], ['Retry-After' => '60']));
    $slept = [];

    try {
        client($transport, $slept)->send([['name' => 'a']]);
        $this->fail('send() did not throw');
    } catch (MiraError $error) {
        expect($error->errorCode)->toBe('rate_limited')
            ->and($error->retryAfterMs)->toBe(60_000);
    }

    expect($slept)->toBe([])->and($transport->requests)->toHaveCount(1);
});

it('reports timeouts as a timeout code', function (): void {
    $transport = transport(new TransportException('slow', timedOut: true));

    expect(fn () => client($transport, maxRetries: 0)->send([['name' => 'a']]))
        ->toThrow(fn (MiraError $error) => expect($error->errorCode)->toBe('timeout'));
});

it('never throws from flush; failures go to onError', function (): void {
    $transport = transport(answer(401, ['code' => 'unauthorized', 'detail' => 'unknown key']));
    $errors = [];
    $slept = [];
    $mira = client($transport, $slept, $errors);

    $mira->track('a');
    $mira->flush();

    expect($errors)->toHaveCount(1)
        ->and($errors[0]->errorCode)->toBe('unauthorized');
});

it('halves a batch the collector finds too large', function (): void {
    $transport = transport(answer(413, ['code' => 'payload_too_large', 'detail' => 'too large']), accepted(2), accepted(2));
    $mira = client($transport);

    foreach (range(1, 4) as $number) {
        $mira->track("event {$number}");
    }

    $mira->flush();

    expect($transport->requests)->toHaveCount(3)
        ->and($transport->body(1)['events'])->toHaveCount(2)
        ->and($transport->body(2)['events'])->toHaveCount(2)
        ->and($transport->body(1)['batch'])->not->toBe($transport->body(2)['batch']);
});

it('splits a buffer that would exceed 1 MiB before sending it', function (): void {
    $transport = transport(accepted(), accepted(), accepted(), accepted(), accepted(), accepted(), accepted(), accepted());
    $mira = client($transport);
    $text = str_repeat('x', 30_000);

    foreach (range(1, 40) as $number) {
        $mira->track('big', properties: ['text' => $text]);
    }

    $mira->flush();

    expect(count($transport->requests))->toBeGreaterThan(1);

    foreach (array_keys($transport->requests) as $index) {
        expect(strlen((string) $transport->requests[$index]['body']))->toBeLessThanOrEqual(1_048_576);
    }
});

it('reports a missing key without a request', function (): void {
    $transport = transport();
    $errors = [];

    $mira = new Mira(key: false, transport: $transport, onError: function (MiraError $error) use (&$errors): void {
        $errors[] = $error;
    });
    $mira->track('a');
    $mira->flush();

    expect($transport->requests)->toBeEmpty()
        ->and($errors[0]->errorCode)->toBe('unauthorized');
});

it('reads the key and host from the environment', function (): void {
    $_ENV['MIRAFIVE_SECRET_KEY'] = 'mf_ab12cd34_fromEnv';
    $_ENV['MIRAFIVE_HOST'] = 'https://collector.example.test';

    try {
        $transport = transport(accepted());
        (new Mira(transport: $transport))->send([['name' => 'a']]);
    } finally {
        unset($_ENV['MIRAFIVE_SECRET_KEY'], $_ENV['MIRAFIVE_HOST']);
    }

    expect($transport->requests[0]['url'])->toBe('https://collector.example.test/v1/batch')
        ->and($transport->requests[0]['headers']['Authorization'])->toBe('Bearer mf_ab12cd34_fromEnv');
});

it('refuses a host without a scheme', function (): void {
    new Mira(key: KEY, host: 'events.mirafive.io');
})->throws(InvalidArgumentException::class);

it('refuses identifiers in consentless mode', function (Closure $call): void {
    $call(client(transport(), mode: Mode::Consentless));
})->with([
    'userId' => [fn (Mira $mira) => $mira->track('a', userId: 'u_42')],
    'anonymousId' => [fn (Mira $mira) => $mira->track('a', anonymousId: 'a1')],
    'sessionId' => [fn (Mira $mira) => $mira->track('a', sessionId: '5f0c1c8e-3e0e-4a57-9d59-3f7f2a6d1e44')],
    'identify' => [fn (Mira $mira) => $mira->identify('u_42')],
    'send' => [fn (Mira $mira) => $mira->send([['name' => 'a', 'userId' => 'u_42']])],
])->throws(InvalidArgumentException::class, 'consentless');

it('sends consentless batches without identifiers', function (): void {
    $transport = transport(accepted());

    client($transport, mode: Mode::Consentless)->send([['name' => 'invoice paid', 'properties' => ['revenue' => 99, 'currency' => 'EUR']]]);

    expect($transport->body(0)['mode'])->toBe('consentless')
        ->and(array_keys($transport->body(0)['events'][0]))->toBe(['name', 'time', 'properties']);
});

it('refuses events the collector would refuse', function (array $event): void {
    client(transport())->send([$event]);
})->with([
    'no name' => [['properties' => []]],
    'blank name' => [['name' => '']],
    'surrounding whitespace' => [['name' => ' signup']],
    'long name' => [['name' => str_repeat('a', 129)]],
    'unknown reserved name' => [['name' => '$signup']],
    'blank user id' => [['name' => 'a', 'userId' => '  ']],
    'long user id' => [['name' => 'a', 'userId' => str_repeat('u', 257)]],
    'session id not a uuid' => [['name' => 'a', 'sessionId' => 'abc']],
    'properties as a list' => [['name' => 'a', 'properties' => ['a', 'b']]],
    'too many values' => [['name' => 'a', 'properties' => array_fill_keys(array_map(fn (int $i): string => "k{$i}", range(1, 65)), 1)]],
    'too deep' => [['name' => 'a', 'properties' => ['a' => ['b' => ['c' => ['d' => ['e' => ['f' => 1]]]]]]]],
    'too large' => [['name' => 'a', 'properties' => ['text' => str_repeat('x', 33_000)]]],
    'not encodable' => [['name' => 'a', 'properties' => ['n' => NAN]]],
    'unknown field' => [['name' => 'a', 'user_id' => 'u_42']],
    'bad time' => [['name' => 'a', 'time' => '2026-09-27']],
])->throws(InvalidArgumentException::class);

it('clamps page fields to the protocol lengths', function (): void {
    $transport = transport(accepted());

    client($transport)->send([['name' => 'a', 'page' => ['title' => str_repeat('🙂', 300), 'url' => 'https://shop.example/']]]);

    $page = $transport->body(0)['events'][0]['page'];

    expect($page['url'])->toBe('https://shop.example/')
        ->and(mb_strlen($page['title']))->toBe(256);
});

it('hands buffered batches off instead of sending them, and a worker delivers them unchanged', function (): void {
    $handedOff = [];
    $transport = transport();
    $mira = new Mira(key: KEY, transport: $transport, flushAt: 2, handOff: function (string $body, string $batchId) use (&$handedOff): void {
        $handedOff[] = [$body, $batchId];
    });

    $mira->track('a');
    $mira->track('b');
    $mira->track('c');
    $mira->flush();

    expect($transport->requests)->toBeEmpty()
        ->and($handedOff)->toHaveCount(2)
        ->and(json_decode($handedOff[0][0], true)['batch'])->toBe($handedOff[0][1])
        ->and(json_decode($handedOff[0][0], true)['events'])->toHaveCount(2);

    $worker = client($workerTransport = transport(answer(503), accepted(2)));
    $receipt = $worker->deliverPrepared($handedOff[0][0]);

    expect($receipt->accepted)->toBe(2)
        ->and($workerTransport->requests[0]['body'])->toBe($handedOff[0][0])
        ->and($workerTransport->requests[1]['body'])->toBe($handedOff[0][0])
        ->and($workerTransport->requests[0]['headers']['Authorization'])->toBe('Bearer '.KEY);
});

it('still sends send() batches immediately when a hand-off is set', function (): void {
    $transport = transport(accepted());
    $mira = new Mira(key: KEY, transport: $transport, handOff: fn (string $body, string $batchId) => throw new LogicException('not for send()'));

    expect($mira->send([['name' => 'a']])->accepted)->toBe(1)
        ->and($transport->requests)->toHaveCount(1);
});

it('reports a failing hand-off instead of throwing from flush', function (): void {
    $errors = [];
    $mira = new Mira(
        key: KEY,
        transport: transport(),
        onError: function (MiraError $error) use (&$errors): void {
            $errors[] = $error;
        },
        handOff: fn (string $body, string $batchId) => throw new RuntimeException('queue down'),
    );

    $mira->track('a');
    $mira->flush();

    expect($errors[0]->errorCode)->toBe('unexpected')
        ->and($errors[0]->getMessage())->toContain('queue down');
});

it('refuses to deliver something that is not an encoded batch, and throws refusals', function (): void {
    $transport = transport(answer(401, ['code' => 'unauthorized', 'detail' => 'unknown key']));
    $mira = client($transport);

    expect(fn () => $mira->deliverPrepared('{"events":[]}'))
        ->toThrow(fn (MiraError $error) => expect($error->errorCode)->toBe('invalid_event'))
        ->and($transport->requests)->toBeEmpty()
        ->and(fn () => $mira->deliverPrepared('{"v":1,"batch":"b","mode":"full","events":[{"name":"a"}]}'))
        ->toThrow(fn (MiraError $error) => expect($error->errorCode)->toBe('unauthorized'));
});

it('registers the shutdown flush only when asked to', function (bool $flushOnShutdown, string $expected): void {
    $script = sprintf(<<<'PHP'
        require %s;
        $GLOBALS['mira'] = new MiraFive\Mira(key: 'k', flushOnShutdown: %s, handOff: function (): void { echo "flushed\n"; });
        $GLOBALS['mira']->track('a');
        register_shutdown_function(function (): void { echo "shutdown\n"; });
        PHP, var_export(dirname(__DIR__, 2).'/vendor/autoload.php', true), var_export($flushOnShutdown, true));

    expect((string) shell_exec(escapeshellarg(PHP_BINARY).' -r '.escapeshellarg($script)))->toBe($expected);
})->with([
    'registered' => [true, "flushed\nshutdown\n"],
    'left to the framework (the destructor still flushes)' => [false, "shutdown\nflushed\n"],
]);

it('sends nothing and needs no key when disabled, but still checks input', function (): void {
    $transport = transport();
    $mira = new Mira(key: false, transport: $transport, enabled: false);

    $mira->track('a', userId: 'u_42');
    $mira->identify('u_42');
    $mira->flush();
    $receipt = $mira->send([['name' => 'a'], ['name' => 'b']], idempotencyKey: 'order-981');
    $prepared = $mira->deliverPrepared('{"v":1,"batch":"b","mode":"full","events":[{"name":"a"}]}');
    $user = $mira->flags()->for(userId: 'u_42');

    expect($transport->requests)->toBeEmpty()
        ->and($receipt)->toEqual(new Receipt(BatchId::fromIdempotencyKey('order-981'), 2, 0))
        ->and($prepared)->toEqual(new Receipt('b', 1, 0))
        ->and($user->variant('pricing-test', 'fallback'))->toBe('fallback')
        ->and($user->enabled('new-checkout', true))->toBeTrue()
        ->and(fn () => $mira->track(' padded'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $mira->send([['name' => '$nope']]))->toThrow(InvalidArgumentException::class);
});

it('passes its flag refresh interval to flags()', function (): void {
    $flags = (new Mira(key: KEY, transport: transport(), flagsRefreshSeconds: 120))->flags();

    expect((new ReflectionProperty($flags, 'refreshMs'))->getValue($flags))->toBe(120_000);
});

it('names the default host', function (): void {
    $transport = transport(accepted());
    (new Mira(key: KEY, transport: $transport))->send([['name' => 'a']]);

    expect(Mira::DEFAULT_HOST)->toBe('https://events.mirafive.io')
        ->and($transport->requests[0]['url'])->toBe(Mira::DEFAULT_HOST.'/v1/batch');
});

it('measures the properties limit as the collector does, with escaped unicode and slashes', function (): void {
    client(transport())->track('a', properties: ['text' => str_repeat('ü', 6_000)]);
})->throws(InvalidArgumentException::class, '32768 bytes');

it('accepts properties the collector measures below the limit', function (): void {
    $transport = transport(accepted());

    client($transport)->send([['name' => 'a', 'properties' => ['text' => str_repeat('x', 32_700)]]]);

    expect($transport->requests)->toHaveCount(1);
});

it('drops the events a buffered batch was refused for and resends the rest under a derived id', function (): void {
    $refusal = answer(400, ['code' => 'validation_failed', 'detail' => 'invalid', 'errors' => [
        ['path' => 'events.1.properties', 'message' => 'too deep'],
        ['path' => 'events.3', 'message' => 'invalid'],
    ]]);
    $transport = transport($refusal, accepted(2));
    $errors = [];
    $slept = [];
    $mira = client($transport, $slept, $errors);

    foreach (['a', 'b', 'c', 'd'] as $name) {
        $mira->track($name, properties: ['nested' => new stdClass]);
    }

    $mira->flush();
    $first = $transport->body(0);
    $second = $transport->body(1);

    expect(array_column($second['events'], 'name'))->toBe(['a', 'c'])
        ->and($second['batch'])->toBe(BatchId::fromIdempotencyKey($first['batch'].'#without:1,3'))
        ->and($transport->requests[1]['body'])->toContain('"nested":{}')
        ->and($errors)->toHaveCount(1)
        ->and($errors[0]->errorCode)->toBe('validation_failed')
        ->and($errors[0]->getMessage())->toContain('Dropped 2 of 4 events');
});

it('recovers a prepared batch the same way, deterministically', function (): void {
    $body = '{"v":1,"batch":"274e05f0-4dd7-8db9-a563-87ea5c891487","mode":"full","events":[{"name":"a"},{"name":"b"}]}';
    $refusal = fn () => answer(400, ['code' => 'validation_failed', 'detail' => 'x', 'errors' => [['path' => 'events.0.name', 'message' => 'bad']]]);
    $first = transport($refusal(), answer(202, ['batch' => 'x', 'accepted' => 1, 'dropped' => 0]));
    $second = transport($refusal(), answer(202, ['batch' => 'x', 'accepted' => 1, 'dropped' => 0]));
    $errors = [];
    $slept = [];

    expect(client($first, $slept, $errors)->deliverPrepared($body)->accepted)->toBe(1);
    client($second, $slept, $errors)->deliverPrepared($body);

    expect($first->requests[1]['body'])->toBe($second->requests[1]['body'])
        ->and($first->body(1)['events'])->toBe([['name' => 'b']]);
});

it('drops the whole batch when the errors name no event', function (): void {
    $transport = transport(answer(400, ['code' => 'validation_failed', 'detail' => 'x', 'errors' => [['path' => 'mode', 'message' => 'bad']]]));
    $errors = [];
    $slept = [];
    $mira = client($transport, $slept, $errors);

    $mira->track('a');
    $mira->flush();

    expect($transport->requests)->toHaveCount(1)
        ->and($errors[0]->errorCode)->toBe('validation_failed')
        ->and($errors[0]->errors)->toBe([['path' => 'mode', 'message' => 'bad']]);
});

it('keeps a flush within its deadline, counting the waits', function (): void {
    $transport = transport(answer(503, [], ['Retry-After' => '2']), answer(503), accepted());
    $errors = [];
    $slept = [];
    $mira = new Mira(key: KEY, transport: $transport, flushDeadlineMs: 2_500, maxRetries: 5, onError: function (MiraError $error) use (&$errors): void {
        $errors[] = $error;
    }, sleep: function (int $ms) use (&$slept): void {
        $slept[] = $ms;
    });

    $mira->track('a');
    $mira->flush();

    expect($slept[0])->toBe(2000)
        ->and($transport->requests[1]['timeoutMs'])->toBeLessThanOrEqual(500)
        ->and($transport->requests[1]['connectTimeoutMs'])->toBeLessThanOrEqual(500)
        ->and(count($transport->requests))->toBeLessThanOrEqual(3);
});

it('gives up a flush when the next wait would pass the deadline', function (): void {
    $transport = transport(answer(503, [], ['Retry-After' => '2']));
    $errors = [];
    $slept = [];
    $mira = new Mira(key: KEY, transport: $transport, flushDeadlineMs: 1_000, onError: function (MiraError $error) use (&$errors): void {
        $errors[] = $error;
    }, sleep: function (int $ms) use (&$slept): void {
        $slept[] = $ms;
    });

    $mira->track('a');
    $mira->flush();

    expect($slept)->toBe([])
        ->and($transport->requests)->toHaveCount(1)
        ->and($errors[0]->status)->toBe(503);
});

it('skips the network for 30 s after a failed flush, shared through the cache, and says so once', function (): void {
    $cache = new ArrayCache;
    $errors = [];
    $report = function (MiraError $error) use (&$errors): void {
        $errors[] = $error;
    };
    $failing = transport(new TransportException('down'), new TransportException('down'), new TransportException('down'));
    $first = new Mira(key: KEY, transport: $failing, cache: $cache, onError: $report, sleep: fn (int $ms) => null);

    $first->track('a');
    $first->flush();
    $first->track('b');
    $first->flush();
    $first->track('c');
    $first->flush();

    $idle = transport();
    $second = new Mira(key: KEY, transport: $idle, cache: $cache, onError: $report);
    $second->track('d');
    $second->flush();

    expect($failing->requests)->toHaveCount(3)
        ->and($idle->requests)->toBeEmpty()
        ->and(array_map(fn (MiraError $error): string => $error->getMessage(), $errors))->toBe([
            'down',
            'Delivery failed recently; events are dropped for up to 30 s without trying the network.',
            'Delivery failed recently; events are dropped for up to 30 s without trying the network.',
        ]);

    foreach (array_keys($cache->items) as $key) {
        $cache->items[$key] = 0;
    }

    $recovered = new Mira(key: KEY, transport: $working = transport(accepted()), cache: $cache);
    $recovered->track('e');
    $recovered->flush();

    expect($working->requests)->toHaveCount(1);
});

it('does not open the breaker for a refusal', function (): void {
    $transport = transport(answer(401, ['code' => 'unauthorized', 'detail' => 'x']), answer(401, ['code' => 'unauthorized', 'detail' => 'x']));
    $mira = client($transport);

    $mira->track('a');
    $mira->flush();
    $mira->track('b');
    $mira->flush();

    expect($transport->requests)->toHaveCount(2);
});

it('refuses an empty idempotency key', function (): void {
    client(transport())->send([['name' => 'a']], idempotencyKey: '');
})->throws(InvalidArgumentException::class, 'non-empty');
