<?php

declare(strict_types=1);

use MiraFive\Consent;
use MiraFive\Mira;
use MiraFive\MiraError;
use MiraFive\Testing\RecordingTransport;
use Psr\Log\AbstractLogger;

beforeEach(function (): void {
    $this->transport = new RecordingTransport;
    $this->errors = [];
    $this->mira = client($this->transport, $this->errors);
});

it('sends a track event in the wire format', function (): void {
    $this->mira->track('report_exported', userId: 'user_42', properties: ['format' => 'csv'], timestamp: '2026-11-08T10:00:00+01:00');
    $this->mira->flush();

    $request = $this->transport->requests()[0];
    $event = $request['batch']['events'][0];

    expect($request['url'])->toBe('https://ingest.test/v1/batch')
        ->and($request['headers']['Authorization'])->toBe('Bearer mira_sk_test')
        ->and($request['headers']['Content-Type'])->toBe('application/json')
        ->and($request['batch'])->toHaveKeys(['sent_at', 'stream', 'seq', 'events'])
        ->and($event)->toMatchArray([
            'type' => 'track',
            'event' => 'report_exported',
            'properties' => ['format' => 'csv'],
            'timestamp' => '2026-11-08T09:00:00.000Z',
            'consent' => 'not_applicable',
            'user_id' => 'user_42',
            'context' => ['library' => ['name' => 'mirafive/sdk-php', 'version' => Mira::VERSION]],
        ])
        ->and($event['event_id'])->toMatch('/^[0-9a-f-]{36}$/')
        ->and($event)->not->toHaveKey('anonymous_id')
        ->and($this->errors)->toBe([]);
});

it('encodes empty properties and traits as objects', function (): void {
    $this->mira->track('heartbeat');
    $this->mira->identify('user_42');
    $this->mira->group('acme');
    $this->mira->flush();

    $body = $this->transport->requests()[0]['body'];

    expect($body)->toContain('"properties":{}')
        ->and(substr_count($body, '"traits":{}'))->toBe(2)
        ->and($body)->not->toContain('[]');
});

it('reports invalid input instead of throwing, and keeps going', function (): void {
    $this->mira->track('');
    $this->mira->track('$pageview');
    $this->mira->identify(' ');
    $this->mira->group('');
    $this->mira->track('ok', eventId: 'not-a-uuid');
    $this->mira->track('ok', timestamp: 'not a time');
    $this->mira->track('ok', consent: ['analytics' => 'maybe']);
    $this->mira->track('ok', consent: 'yes');
    $this->mira->track('fine');
    $this->mira->flush();

    expect(messages($this->errors))->toBe([
        'track: event is required',
        'track: "$pageview" is reserved (names starting with $)',
        'identify: userId is required',
        'group: groupId is required',
        'ok: eventId must be a UUID (see Mira::eventIdFor())',
        'Invalid timestamp: not a time',
        'consent: "maybe" is not granted, denied or unknown',
        "consent must be 'not_applicable', a Consent, or ['analytics' => …, 'marketing' => …]",
    ])->and(array_column(sent($this->transport), 'event'))->toBe(['fine']);
});

it('stamps consent as the wire expects', function (mixed $consent, mixed $wire): void {
    $this->mira->track('step', anonymousId: 'a', consent: $consent);
    $this->mira->flush();

    expect(sent($this->transport)[0]['consent'])->toBe($wire);
})->with([
    'default' => [null, 'not_applicable'],
    'not applicable' => ['not_applicable', 'not_applicable'],
    'booleans' => [['analytics' => true, 'marketing' => false], ['analytics' => 'granted', 'marketing' => 'denied']],
    'analytics alone' => [['analytics' => true], ['analytics' => 'granted', 'marketing' => 'unknown']],
    'states' => [['analytics' => 'unknown', 'marketing' => 'denied'], ['analytics' => 'unknown', 'marketing' => 'denied']],
    'granted' => [Consent::granted(), ['analytics' => 'granted', 'marketing' => 'granted']],
    'analytics only' => [Consent::analyticsOnly(), ['analytics' => 'granted', 'marketing' => 'denied']],
    'denied' => [Consent::denied(), ['analytics' => 'denied', 'marketing' => 'denied']],
]);

it('takes timestamps as dates, epoch milliseconds and strings', function (): void {
    $this->mira->track('a', timestamp: new DateTimeImmutable('2026-11-08T08:00:00.123+00:00'));
    $this->mira->track('b', timestamp: 1_794_124_980_007);
    $this->mira->track('c', timestamp: -1);
    $this->mira->track('d', timestamp: '2020-01-01');
    $this->mira->flush();

    expect(array_column(sent($this->transport), 'timestamp'))->toBe([
        '2026-11-08T08:00:00.123Z',
        '2026-11-08T08:03:00.007Z',
        '1969-12-31T23:59:59.999Z',
        '2020-01-01T00:00:00.000Z',
    ]);
});

it('lowercases a given event id and keeps it', function (): void {
    $id = strtoupper(Mira::eventIdFor('order:1'));
    $this->mira->track('a', eventId: $id);
    $this->mira->flush();

    expect(sent($this->transport)[0]['event_id'])->toBe(strtolower($id));
});

it('keeps known context fields and drops only an invalid location', function (): void {
    $context = [
        'ip' => '203.0.113.7',
        'userAgent' => 'UA',
        'page' => ['url' => 'https://shop.example/', 'referrer' => '', 'title' => 'Shop', 'other' => 'x'],
        'app' => ['name' => 'App', 'version' => '1.0'],
        'locale' => 'de-DE',
        'timezone' => 'Europe/Berlin',
        'unknown' => 'ignored',
    ];

    $this->mira->track('near_me', context: [...$context, 'location' => ['latitude' => 52.52, 'longitude' => 13.4]]);
    $this->mira->track('far_away', context: ['location' => ['latitude' => 91, 'longitude' => 0]]);
    $this->mira->flush();

    [$near, $far] = sent($this->transport);

    expect($near['context'])->toBe([
        'library' => ['name' => 'mirafive/sdk-php', 'version' => Mira::VERSION],
        'ip' => '203.0.113.7',
        'user_agent' => 'UA',
        'locale' => 'de-DE',
        'timezone' => 'Europe/Berlin',
        'page' => ['url' => 'https://shop.example/', 'title' => 'Shop'],
        'app' => ['name' => 'App', 'version' => '1.0'],
        'location' => ['latitude' => 52.52, 'longitude' => 13.4],
    ])->and($far['context'])->not->toHaveKey('location')
        ->and(messages($this->errors))->toBe(['far_away: location dropped (latitude -90..90, longitude -180..180)']);
});

it('sends identify with known external ids only', function (): void {
    $this->mira->identify('user_42', traits: ['email' => 'ada@example.com', 'plan' => ['name' => 'pro']], externalIds: [
        'stripe_customer' => 'cus_123',
        'shopify_customer' => '',
        'revenuecat_app_user' => str_repeat('x', 201),
        'paypal' => 'P-1',
    ]);
    $this->mira->flush();

    $event = sent($this->transport)[0];

    expect($event)->toMatchArray([
        'type' => 'identify',
        'event' => '$identify',
        'user_id' => 'user_42',
        'traits' => ['email' => 'ada@example.com', 'plan.name' => 'pro'],
        'external_ids' => ['stripe_customer' => 'cus_123'],
    ])->and(messages($this->errors)[0])->toStartWith('identify: unknown external id type "paypal" dropped');
});

it('leaves external_ids out when none is usable', function (): void {
    $this->mira->identify('user_42', externalIds: ['stripe_customer' => '  ']);
    $this->mira->flush();

    expect(sent($this->transport)[0])->not->toHaveKey('external_ids');
});

it('sends group events', function (): void {
    $this->mira->group('acme', userId: 'user_42', traits: ['name' => 'Acme GmbH', 'seats' => 12]);
    $this->mira->flush();

    expect(sent($this->transport)[0])->toMatchArray([
        'type' => 'group',
        'event' => '$group',
        'group_id' => 'acme',
        'user_id' => 'user_42',
        'traits' => ['name' => 'Acme GmbH', 'seats' => 12],
    ]);
});

it('sends revenue with every ledger field, and not the deprecated fee and tax', function (): void {
    $this->mira->revenue(
        amount: 999,
        currency: 'eur',
        userId: 'user_42',
        entryId: 'revenuecat:txn_1',
        entryKind: 'subscription_payment',
        fee: 150,
        tax: 160.0,
        product: 'premium_monthly',
        subscription: ['id' => 'sub_42', 'interval' => 'month', 'intervalCount' => 3, 'periodStart' => '2026-11-08T00:00:00Z', 'periodEnd' => 1_802_044_800_000],
        properties: ['coupon' => 'X'],
    );
    $this->mira->revenue(amount: -999, currency: 'EUR', entryKind: 'refund', entryId: 'revenuecat:txn_1:refund', refundOf: 'revenuecat:txn_1');
    $this->mira->flush();

    [$payment, $refund] = sent($this->transport);

    expect($payment)->toMatchArray([
        'type' => 'track',
        'event' => '$revenue',
        'properties' => ['coupon' => 'X'],
        'revenue' => [
            'amount' => 999,
            'currency' => 'EUR',
            'entry_id' => 'revenuecat:txn_1',
            'kind' => 'subscription_payment',
            'product' => 'premium_monthly',
            'subscription' => [
                'id' => 'sub_42',
                'interval' => 'month',
                'interval_count' => 3,
                'period_start' => '2026-11-08T00:00:00.000Z',
                'period_end' => '2027-02-08T00:00:00.000Z',
            ],
        ],
    ])->and($payment['revenue'])->not->toHaveKeys(['fee', 'tax'])->and($refund['revenue'])->toBe([
        'amount' => -999,
        'currency' => 'EUR',
        'entry_id' => 'revenuecat:txn_1:refund',
        'kind' => 'refund',
        'refund_of' => 'revenuecat:txn_1',
    ])->and($this->errors)->toBe([]);
});

it('refuses revenue that is not in integer minor units or misses a field', function (): void {
    $this->mira->revenue(amount: 19.99, currency: 'EUR');
    $this->mira->revenue(amount: 1999, currency: 'euro');
    $this->mira->revenue(amount: 1999, currency: 'EUR', entryKind: 'payout');
    $this->mira->revenue(amount: 1999, currency: 'EUR', subscription: ['interval' => 'month']);
    $this->mira->revenue(amount: 1999, currency: 'EUR', subscription: ['id' => 's', 'interval' => 'fortnight']);
    $this->mira->revenue(amount: 1999, currency: 'EUR', subscription: ['id' => 's', 'intervalCount' => 0]);
    $this->mira->revenue(amount: NAN, currency: 'EUR');
    $this->mira->flush();

    expect(messages($this->errors))->toBe([
        'revenue: amount must be an integer in minor units (1999 for 19.99)',
        'revenue: currency must be an ISO 4217 code',
        'revenue: entryKind must be one of charge, subscription_payment, refund, dispute, dispute_won',
        'revenue: subscription.id is required',
        'revenue: subscription.interval must be one of day, week, month, year',
        'revenue: subscription.intervalCount must be an integer from 1 to 36',
        'revenue: amount must be an integer in minor units (1999 for 19.99)',
    ])->and($this->transport->requests())->toBe([]);
});

it('sends a touchpoint with its fields over the properties', function (): void {
    $this->mira->touchpoint(
        kind: 'offline',
        userId: 'user_42',
        channel: 'paid_social',
        source: 'instagram',
        medium: 'paid',
        campaign: 'autumn',
        content: 'carousel',
        campaignId: 'c1',
        adGroupId: 'g1',
        adId: 'a1',
        code: 'CODE',
        answer: '',
        externalRef: 'hubspot:1',
        properties: ['booth' => 'B12', 'kind' => 'overwritten'],
    );
    $this->mira->flush();

    expect(sent($this->transport)[0])->toMatchArray([
        'type' => 'track',
        'event' => '$touchpoint',
        'properties' => [
            'booth' => 'B12',
            'kind' => 'offline',
            'channel' => 'paid_social',
            'source' => 'instagram',
            'medium' => 'paid',
            'campaign' => 'autumn',
            'content' => 'carousel',
            'campaign_id' => 'c1',
            'ad_group_id' => 'g1',
            'ad_id' => 'a1',
            'code' => 'CODE',
            'external_ref' => 'hubspot:1',
        ],
    ]);
});

it('refuses a touchpoint without kind, channel or source, or person', function (): void {
    $this->mira->touchpoint('billboard', userId: 'u', source: 's');
    $this->mira->touchpoint('survey', userId: 'u', channel: 'tv');
    $this->mira->touchpoint('survey', userId: 'u', source: '  ');
    $this->mira->touchpoint('survey', source: 'podcast');
    $this->mira->touchpoint('survey', anonymousId: 'a', source: 'podcast');
    $this->mira->flush();

    expect(messages($this->errors))->toBe([
        'touchpoint: kind must be one of survey, offline, code, custom',
        'touchpoint: channel must be one of paid_search, paid_social, paid_other, organic_search, organic_social, ai_assistant, email, affiliate, display, video, qr, app_store, referral, direct',
        'touchpoint: a channel or a source is required',
        'touchpoint: a userId or an anonymousId is required',
    ])->and(sent($this->transport))->toHaveCount(1);
});

it('fills in a scope\'s defaults, and the call wins', function (): void {
    $request = $this->mira->with(userId: 'user_42', consent: Consent::analyticsOnly(), context: ['ip' => '203.0.113.7', 'locale' => 'de-DE']);
    $request->track('a');
    $request->track('b', userId: 'user_7', consent: 'not_applicable', context: ['locale' => 'en-US']);
    $request->touchpoint('survey', source: 'podcast');
    $request->with(anonymousId: 'anon_1')->track('c');
    $this->mira->track('d');
    $request->flush();

    [$a, $b, $touchpoint, $c, $d] = sent($this->transport);

    expect($a)->toMatchArray(['user_id' => 'user_42', 'consent' => ['analytics' => 'granted', 'marketing' => 'denied']])
        ->and($a['context'])->toMatchArray(['ip' => '203.0.113.7', 'locale' => 'de-DE'])
        ->and($b)->toMatchArray(['user_id' => 'user_7', 'consent' => 'not_applicable'])
        ->and($b['context'])->toMatchArray(['ip' => '203.0.113.7', 'locale' => 'en-US'])
        ->and($touchpoint['user_id'])->toBe('user_42')
        ->and($c)->toMatchArray(['user_id' => 'user_42', 'anonymous_id' => 'anon_1'])
        ->and($d)->not->toHaveKey('user_id')
        ->and($this->transport->requests())->toHaveCount(1);
});

it('refuses events after shutdown', function (): void {
    $this->mira->track('before');
    $this->mira->shutdown();
    $this->mira->track('after');

    expect(array_column(sent($this->transport), 'event'))->toBe(['before'])
        ->and(messages($this->errors))->toBe(['after: the client was shut down']);
});

it('reports a missing key and sends nothing', function (): void {
    $errors = [];
    $mira = client($this->transport, $errors, ['secretKey' => '']);
    $mira->track('a');
    $mira->flush();

    expect($mira->sends)->toBeFalse()
        ->and(messages($errors))->toBe(['no secret key: pass secretKey or set MIRAFIVE_SECRET_KEY; events will be dropped'])
        ->and($this->transport->requests())->toBe([]);
});

it('reads the key and host from the environment', function (): void {
    $_ENV['MIRAFIVE_SECRET_KEY'] = 'mira_sk_env';
    $_ENV['MIRAFIVE_HOST'] = 'http://localhost:4500/';

    try {
        $mira = new Mira(transport: $this->transport, flushOnShutdown: false);
        $mira->track('a');
        $mira->flush();
    } finally {
        unset($_ENV['MIRAFIVE_SECRET_KEY'], $_ENV['MIRAFIVE_HOST']);
    }

    expect($mira->host)->toBe('http://localhost:4500')
        ->and($this->transport->requests()[0]['url'])->toBe('http://localhost:4500/v1/batch')
        ->and($this->transport->requests()[0]['headers']['Authorization'])->toBe('Bearer mira_sk_env');
});

it('defaults to the MIRA FIVE ingest', function (): void {
    expect(client($this->transport, $this->errors, ['host' => null])->host)->toBe('https://events.mirafive.io');
});

it('refuses a host that is not an origin', function (): void {
    new Mira(secretKey: 'mira_sk_x', host: 'events.mirafive.io');
})->throws(InvalidArgumentException::class);

it('refuses a public key clearly and sends nothing', function (): void {
    $errors = [];
    $mira = client($this->transport, $errors, ['secretKey' => 'mira_pk_live_123']);
    $mira->track('a');
    $mira->flush();

    expect(messages($errors)[0])->toStartWith('this is a public key (mira_pk_…): server code needs the project\'s secret key (mira_sk_…)')
        ->and($this->transport->requests())->toHaveCount(0);
});

it('checks input but sends nothing when disabled', function (): void {
    $errors = [];
    $mira = client($this->transport, $errors, ['enabled' => false, 'secretKey' => '']);
    $mira->track('');
    $mira->track('ok');
    $mira->flush();

    expect($mira->sends)->toBeFalse()
        ->and(messages($errors))->toBe(['track: event is required'])
        ->and($this->transport->requests())->toBe([]);
});

it('survives an onError that throws', function (): void {
    $mira = client($this->transport, $this->errors, ['onError' => static function (MiraError $error): void {
        throw new RuntimeException('handler broke');
    }]);

    $mira->track('');
    $mira->track('ok');
    $mira->flush();

    expect(sent($this->transport))->toHaveCount(1);
});

it('falls back to a PSR-3 logger', function (): void {
    $logger = new class extends AbstractLogger
    {
        /** @var list<string> */
        public array $lines = [];

        public function log($level, string|Stringable $message, array $context = []): void
        {
            $this->lines[] = $level.' '.str_replace('{message}', (string) ($context['message'] ?? ''), (string) $message);
        }
    };

    $mira = new Mira(secretKey: 'mira_sk_test', host: 'https://ingest.test', logger: $logger, transport: $this->transport, flushOnShutdown: false);
    $mira->track('');

    expect($logger->lines)->toBe(['warning [mirafive] track: event is required']);
});
