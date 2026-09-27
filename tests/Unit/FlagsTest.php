<?php

declare(strict_types=1);

use MiraFive\Flags\ErrorCode;
use MiraFive\Flags\Hash;
use MiraFive\Flags\MiraFlags;
use MiraFive\Flags\Reason;
use MiraFive\Http\Response;
use MiraFive\Http\TransportException;
use MiraFive\Mira;
use MiraFive\MiraError;
use MiraFive\Tests\Support\ArrayCache;
use MiraFive\Tests\Support\FakeTransport;

const FLAGS_KEY = 'mf_ab12cd34_secretTail';

function flagDocument(?int $at = null): string
{
    $at ??= (int) floor(microtime(true) * 1000);
    $everyone = fn (string $variant): array => [['x' => $variant]];
    $split = [['w' => [['a', 5000], ['b', 5000]]]];

    return json_encode(['at' => $at, 'v' => 1, 'flags' => [
        'new-checkout' => ['s' => 'k3v9x0q2m7ta', 't' => 'b', 'u' => 'p', 'd' => 'off', 'r' => $everyone('on'), 'w' => 1],
        'pricing-test' => ['s' => '3f9a1c0b7e2d', 't' => 'm', 'u' => 'p', 'd' => 'a', 'r' => $split, 'p' => ['a' => ['price' => 10], 'b' => ['price' => 12]], 'e' => 'r', 'c' => 's'],
        'hero' => ['s' => 'q1w2e3r4t5y6', 't' => 'm', 'u' => 'p', 'd' => 'a', 'r' => $split, 'e' => 'r', 'c' => 'b', 'w' => 1],
        'limits' => ['s' => 'z9x8c7v6b5n4', 't' => 'c', 'u' => 'p', 'd' => 'default', 'r' => $everyone('default'), 'p' => ['default' => ['max' => 3, 'labels' => new stdClass]], 'w' => 1],
        'banner' => ['s' => 'a1s2d3f4g5h6', 't' => 'c', 'u' => 'p', 'd' => 'shown', 'r' => $everyone('shown'), 'p' => ['shown' => "</script><script>alert(1)</script>&\u{2028}"], 'w' => 1],
        'server-only' => ['s' => 'p0o9i8u7y6t5', 't' => 'c', 'u' => 'p', 'd' => 'on', 'r' => $everyone('on'), 'p' => ['on' => 'internal-token']],
        'by-browser' => ['s' => 'l1k2j3h4g5f6', 't' => 'b', 'u' => 'b', 'd' => 'off', 'r' => [['w' => [['on', 10000]]]], 'w' => 1],
        'beta' => ['s' => 'm1n2b3v4c5x6', 't' => 'b', 'u' => 'p', 'd' => 'off', 'r' => [['if' => [['s', 'seg_a']], 'x' => 'on']]],
    ]], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function documentAnswer(?string $etag = 'W/"f-abc"'): Response
{
    return new Response(200, $etag === null ? [] : ['ETag' => $etag], flagDocument());
}

function membership(string ...$segments): Response
{
    return answer(200, ['units' => [['segments' => $segments, 'unavailable' => [], 'refreshedAt' => null, 'stale' => false]]]);
}

/**
 * @param  list<MiraError>  $errors
 */
function flags(FakeTransport $transport, array &$errors = [], ?ArrayCache $cache = null, string|array|null $document = null): MiraFlags
{
    return new MiraFlags(
        key: FLAGS_KEY,
        host: 'https://events.example.test',
        cache: $cache,
        document: $document,
        transport: $transport,
        onError: function (MiraError $error) use (&$errors): void {
            $errors[] = $error;
        },
    );
}

it('fetches the server document with the secret key and evaluates flags', function (): void {
    $transport = transport(documentAnswer());
    $user = flags($transport)->for(userId: 'u_42');

    expect($transport->requests[0]['method'])->toBe('GET')
        ->and($transport->requests[0]['url'])->toBe('https://events.example.test/v1/flags')
        ->and($transport->requests[0]['headers']['Authorization'])->toBe('Bearer '.FLAGS_KEY)
        ->and($transport->requests[0]['headers'])->not->toHaveKeys(['Origin', 'Sec-Fetch-Site'])
        ->and($user->enabled('new-checkout'))->toBeTrue()
        ->and($user->variant('new-checkout'))->toBe('on')
        ->and($user->config('limits', []))->toBe(['max' => 3, 'labels' => []])
        ->and($user->evaluate('new-checkout')->reason)->toBe(Reason::Static)
        ->and($user->evaluate('nope')->errorCode)->toBe(ErrorCode::FlagNotFound)
        ->and($user->variant('nope', 'fallback'))->toBe('fallback')
        ->and($user->enabled('nope', true))->toBeTrue();
});

it('answers the code fallback while no document is in hand, and retries only after the interval', function (): void {
    $transport = transport(new TransportException('down'));
    $errors = [];
    $flags = flags($transport, $errors);

    $user = $flags->for(userId: 'u_42');

    expect($user->evaluate('new-checkout')->errorCode)->toBe(ErrorCode::NotReady)
        ->and($user->enabled('new-checkout', true))->toBeTrue()
        ->and($user->config('limits', ['max' => 1]))->toBe(['max' => 1])
        ->and($errors[0]->errorCode)->toBe('network_error')
        ->and($flags->status())->toBe(['ready' => false, 'etag' => null, 'fetchedAt' => null]);

    $flags->for(userId: 'u_42');

    expect($transport->requests)->toHaveCount(1);
});

it('uses a snapshot document while none was fetched, but not one older than 7 days', function (): void {
    $fresh = flags(transport(new TransportException('down')), document: flagDocument())->for(userId: 'u_42');
    $old = flags(transport(new TransportException('down')), document: flagDocument(at: 1_000))->for(userId: 'u_42');

    expect($fresh->variant('new-checkout'))->toBe('on')
        ->and($old->variant('new-checkout'))->toBeNull();
});

it('revalidates a shared cached document with its ETag', function (): void {
    $cache = new ArrayCache;
    $first = flags(transport(documentAnswer('W/"f-1"')), cache: $cache);
    $first->ready();

    $key = array_key_first($cache->items);
    $cache->items[$key]['checkedAt'] -= 60_000;

    $transport = transport(new Response(304, ['ETag' => 'W/"f-1"']));
    $second = flags($transport, cache: $cache);

    expect($second->for()->variant('new-checkout'))->toBe('on')
        ->and($transport->requests[0]['headers']['If-None-Match'])->toBe('W/"f-1"')
        ->and($cache->items[$key]['checkedAt'])->toBeGreaterThan(microtime(true) * 1000 - 5_000);

    $third = flags($idle = transport(), cache: $cache);

    expect($third->for()->variant('new-checkout'))->toBe('on')
        ->and($idle->requests)->toBeEmpty();
});

it('stops asking with a refused key', function (): void {
    $errors = [];
    $flags = flags(transport(answer(401, ['code' => 'unauthorized', 'detail' => 'unknown key'])), $errors);

    expect($flags->ready())->toBeFalse()
        ->and($errors[0]->errorCode)->toBe('unauthorized');
});

it('looks up segment membership once per unit', function (): void {
    $transport = transport(documentAnswer(), membership('seg_a'));
    $flags = flags($transport);

    expect($flags->for(userId: 'u_42')->enabled('beta'))->toBeTrue()
        ->and($flags->for(userId: 'u_42')->enabled('beta'))->toBeTrue()
        ->and($transport->requests)->toHaveCount(2)
        ->and($transport->requests[1]['method'])->toBe('POST')
        ->and($transport->requests[1]['url'])->toBe('https://events.example.test/v1/flags/segments')
        ->and($transport->requests[1]['timeoutMs'])->toBe(500)
        ->and($transport->body(1))->toBe(['units' => [['userId' => 'u_42']]]);
});

it('treats segments as unavailable when the lookup fails, and pauses lookups', function (): void {
    $transport = transport(documentAnswer(), answer(503, ['code' => 'unavailable', 'detail' => 'down']));
    $errors = [];
    $flags = flags($transport, $errors);

    $user = $flags->for(userId: 'u_42');

    expect($user->enabled('beta'))->toBeFalse()
        ->and($user->evaluate('beta')->errorCode)->toBe(ErrorCode::MembershipUnavailable)
        ->and($user->evaluate('new-checkout')->errorCode)->toBeNull()
        ->and($errors)->toHaveCount(1);

    $flags->for(userId: 'u_7');

    expect($transport->requests)->toHaveCount(2);
});

it('does not look up a unit without ids', function (): void {
    $transport = transport(documentAnswer());

    expect(flags($transport)->for(properties: ['plan' => 'pro'])->evaluate('beta')->errorCode)->toBe(ErrorCode::MembershipUnavailable)
        ->and($transport->requests)->toHaveCount(1);
});

it('counts server-counted experiments once, on exposing reads only', function (): void {
    $transport = transport(documentAnswer(), membership(), accepted());
    $mira = new Mira(key: FLAGS_KEY, host: 'https://events.example.test', transport: $transport);
    $user = $mira->flags()->for(userId: 'u_42');

    $evaluation = $user->evaluate('pricing-test');
    $mira->flush();

    expect($evaluation->reason)->toBe(Reason::Split)
        ->and($transport->requests)->toHaveCount(2);

    $variant = $user->variant('pricing-test');
    $user->config('pricing-test');
    $mira->flush();
    $event = $transport->body(2)['events'][0];

    expect($transport->body(2)['events'])->toHaveCount(1)
        ->and($event['name'])->toBe('$exposure')
        ->and($event['userId'])->toBe('u_42')
        ->and($event['properties'])->toBe(['$experiment' => 'pricing-test', '$variant' => $variant])
        ->and($user->config('pricing-test'))->toBe(['price' => $variant === 'a' ? 10 : 12]);
});

it('leaves browser-counted experiments to the browser', function (): void {
    $evaluation = flags(transport(documentAnswer()))->for(userId: 'u_42')->evaluate('hero');

    expect($evaluation->variant)->toBe('a')
        ->and($evaluation->reason)->toBe(Reason::Default)
        ->and($evaluation->errorCode)->toBe(ErrorCode::NotAllowed);
});

it('bootstraps the website flags as an escaped JSON script block', function (): void {
    $flags = flags(transport(documentAnswer()));
    $html = $flags->for(userId: 'u_42')->bootstrap();

    expect($html)->toStartWith('<script type="application/json" id="mirafive-flags">')
        ->toEndWith('</script>')
        ->and(substr_count($html, '</script>'))->toBe(1)
        ->and(substr_count($html, '<'))->toBe(2)
        ->and($html)->toContain('\u003c/script\u003e\u003cscript\u003e')
        ->toContain('\u0026\u2028')
        ->not->toContain('&')
        ->not->toContain("\u{2028}")
        ->not->toContain('internal-token')
        ->toContain('"labels":{}');

    $json = substr($html, strlen('<script type="application/json" id="mirafive-flags">'), -strlen('</script>'));
    $bootstrap = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
    $hero = $bootstrap['values']['hero'];

    expect(array_keys($bootstrap))->toBe(['v', 'at', 'values', 'browser', 'unit'])
        ->and($bootstrap['at'])->toBe($flags->status()['fetchedAt'])
        ->and($bootstrap['values']['new-checkout'])->toBe(['on'])
        ->and($bootstrap['values']['banner'])->toBe(['shown', "</script><script>alert(1)</script>&\u{2028}"])
        ->and($bootstrap['values']['limits'])->toBe(['default', ['max' => 3, 'labels' => []]])
        ->and($hero[0])->toBeIn(['a', 'b'])
        ->and(array_slice($hero, 1))->toBe([null, 1])
        ->and($bootstrap['values'])->not->toHaveKeys(['pricing-test', 'server-only', 'beta', 'by-browser'])
        ->and($bootstrap['browser'])->toBe(['by-browser'])
        ->and($bootstrap['unit'])->toBe((string) Hash::fnv1a32('u_42'))
        ->and(MiraFlags::BOOTSTRAP_HEADERS)->toBe(['Cache-Control' => 'private, no-store']);
});

it('bootstraps an empty values object without a document', function (): void {
    $html = flags(transport(new TransportException('down')))->for()->bootstrap();

    expect($html)->toContain('"values":{}')->not->toContain('unit');
});

it('ignores junk ids and reads the uuid part of an anonymous id', function (): void {
    $transport = transport(documentAnswer(), membership());
    $flags = flags($transport);

    $flags->for(userId: 'undefined', anonymousId: '5f0c1c8e-3e0e-4a57-9d59-3f7f2a6d1e44.1727430000000');

    expect($transport->body(1))->toBe(['units' => [['anonymousId' => '5f0c1c8e-3e0e-4a57-9d59-3f7f2a6d1e44']]]);
});

it('uses the anonymous id only with experiments consent, and shows experiments their default without it', function (): void {
    $transport = transport(documentAnswer(), membership(), membership());
    $mira = new Mira(key: FLAGS_KEY, host: 'https://events.example.test', transport: $transport);
    $anonymousId = '5f0c1c8e-3e0e-4a57-9d59-3f7f2a6d1e44';

    $consented = $mira->flags()->for(userId: 'u_42', anonymousId: $anonymousId);
    $declined = $mira->flags()->for(userId: 'u_42', anonymousId: $anonymousId, consent: ['experiments' => false]);

    expect($consented->evaluate('by-browser')->reason)->toBe(Reason::Split)
        ->and($declined->evaluate('by-browser')->reason)->toBe(Reason::Default)
        ->and($declined->variant('pricing-test'))->toBe('a')
        ->and($declined->evaluate('pricing-test')->errorCode)->toBe(ErrorCode::NotAllowed)
        ->and($declined->evaluate('new-checkout')->reason)->toBe(Reason::Static);

    $bootstrap = $declined->bootstrap();
    $mira->flush();

    expect($bootstrap)->toContain('"hero":["a"]')
        ->and($transport->requests)->toHaveCount(3)
        ->and($transport->body(1)['units'])->toBe([['userId' => 'u_42', 'anonymousId' => $anonymousId]])
        ->and($transport->body(2)['units'])->toBe([['userId' => 'u_42']]);
});

it('does not look up segments without targeting consent', function (): void {
    $transport = transport(documentAnswer());
    $user = flags($transport)->for(userId: 'u_42', consent: ['targeting' => false]);

    expect($user->enabled('beta'))->toBeFalse()
        ->and($user->evaluate('beta')->errorCode)->toBe(ErrorCode::NotAllowed)
        ->and($transport->requests)->toHaveCount(1);
});

it('gives an opted-out person no unit, no segment lookup and no exposure', function (): void {
    $transport = transport(documentAnswer());
    $mira = new Mira(key: FLAGS_KEY, host: 'https://events.example.test', transport: $transport);

    $user = $mira->flags()->for(
        userId: 'u_42',
        anonymousId: '5f0c1c8e-3e0e-4a57-9d59-3f7f2a6d1e44',
        consent: ['experiments' => true, 'targeting' => true],
        optedOut: true,
    );

    expect($user->enabled('new-checkout'))->toBeTrue()
        ->and($user->variant('pricing-test'))->toBe('a')
        ->and($user->evaluate('by-browser')->reason)->toBe(Reason::Default)
        ->and($user->evaluate('beta')->errorCode)->toBe(ErrorCode::NotAllowed)
        ->and($user->bootstrap())->not->toContain('"unit"');

    $mira->flush();

    expect($transport->requests)->toHaveCount(1);
});

it('refuses unknown consent scopes', function (): void {
    flags(transport(documentAnswer()))->for(userId: 'u_42', consent: ['experiment' => false]);
})->throws(InvalidArgumentException::class, 'experiment');
