<?php

declare(strict_types=1);

/**
 * The cross-language contract: prints, as one JSON array, the request bodies this SDK sends for a
 * fixed scenario that uses every method with every option. Times, ids and the stream are fixed, so
 * the output is the same on every run. The monorepo validates it against the Zod wire schema
 * (`packages/schema`).
 *
 *     composer install && php tests/contract/emit.php
 */

use MiraFive\Consent;
use MiraFive\Mira;
use MiraFive\MiraError;
use MiraFive\Testing\RecordingTransport;
use Psr\Clock\ClockInterface;

require __DIR__.'/../../vendor/autoload.php';

$clock = new class implements ClockInterface
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-11-08T09:30:00.250Z');
    }
};

$transport = new RecordingTransport;
$problems = [];
$mira = new Mira(
    secretKey: 'mira_sk_contract',
    host: 'https://events.example.test',
    flushAt: 4,
    onError: static function (MiraError $error) use (&$problems): void {
        $problems[] = $error->getMessage();
    },
    transport: $transport,
    flushOnShutdown: false,
    clock: $clock,
    sleep: static function (): void {},
    stream: '0196f3a2-7c4d-7e10-8a5b-3c2d1e0f9a8b',
);

$id = static fn (string $key): string => Mira::eventIdFor('contract:'.$key);
$browser = [
    'ip' => '203.0.113.7',
    'userAgent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 15_0) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/19.0 Safari/605.1.15',
    'page' => ['url' => 'https://shop.example/checkout?step=2', 'referrer' => 'https://www.google.com/', 'title' => 'Checkout'],
    'locale' => 'de-DE',
    'timezone' => 'Europe/Berlin',
];

$mira->identify(
    userId: 'user_42',
    traits: ['email' => 'ada@example.com', 'name' => 'Ada Lovelace', 'plan' => ['name' => 'pro', 'seats' => 12], 'beta' => true],
    externalIds: ['stripe_customer' => 'cus_123', 'revenuecat_app_user' => 'rc_42', 'shopify_customer' => '7781', 'shopware_customer' => 'sw-9'],
    anonymousId: '0196f3a2-0000-7000-8000-00000000aaaa',
    eventId: $id('identify'),
    timestamp: '2026-11-08T09:00:00+01:00',
    consent: Consent::granted(),
    context: [...$browser, 'location' => ['latitude' => 52.520008, 'longitude' => 13.404954]],
);

$mira->group(
    groupId: 'acme',
    userId: 'user_42',
    traits: ['name' => 'Acme GmbH', 'seats' => 12, 'industry' => 'software'],
    eventId: $id('group'),
    timestamp: new DateTimeImmutable('2026-11-08T08:01:00Z'),
);

$mira->track(
    event: 'report_exported',
    userId: 'user_42',
    properties: [
        'format' => 'csv',
        'rows' => 1200,
        'ratio' => 0.75,
        'filters' => ['country' => 'DE', 'range' => ['from' => '2026-10-01', 'to' => '2026-10-31']],
        'tags' => ['q4', 'board', 3, false],
        'exported_at' => new DateTimeImmutable('2026-11-08T08:02:00Z'),
        'note' => null,
    ],
    eventId: $id('track-properties'),
    timestamp: 1_794_124_980_000,
    context: ['app' => ['name' => 'Backoffice', 'version' => '4.2.0', 'build' => '4200']],
);

// No properties at all: they must still encode as an object.
$mira->track(event: 'heartbeat', anonymousId: 'anon_7', eventId: $id('track-empty'), timestamp: '2026-11-08T08:04:00Z');

$consents = [
    'not_applicable' => 'not_applicable',
    'granted' => Consent::granted(),
    'analytics-only' => Consent::analyticsOnly(),
    'denied' => Consent::denied(),
    'booleans' => ['analytics' => true, 'marketing' => false],
    'states' => ['analytics' => 'unknown', 'marketing' => 'unknown'],
    'analytics-alone' => ['analytics' => 'granted'],
];

foreach ($consents as $name => $consent) {
    $mira->track(
        event: 'consent_variant',
        anonymousId: 'anon_7',
        properties: ['variant' => $name],
        eventId: $id('consent-'.$name),
        timestamp: '2026-11-08T08:05:00Z',
        consent: $consent,
        context: $browser,
    );
}

$mira->revenue(
    amount: 4900,
    currency: 'eur',
    userId: 'user_42',
    entryId: 'stripe:in_123',
    entryKind: 'charge',
    product: 'lifetime',
    properties: ['coupon' => 'WELCOME10'],
    eventId: Mira::eventIdFor('order:123:paid'),
    timestamp: '2026-11-08T08:10:00Z',
);

$mira->revenue(
    amount: 999,
    currency: 'EUR',
    userId: 'user_42',
    entryId: 'revenuecat:txn_1000000123',
    product: 'premium_quarterly',
    subscription: [
        'id' => 'sub_42',
        'interval' => 'month',
        'intervalCount' => 3,
        'periodStart' => '2026-11-08T08:11:00Z',
        'periodEnd' => new DateTimeImmutable('2027-02-08T08:11:00Z'),
    ],
    eventId: $id('revenue-subscription'),
    timestamp: '2026-11-08T08:11:00Z',
);

$mira->revenue(
    amount: -999,
    currency: 'EUR',
    userId: 'user_42',
    entryId: 'revenuecat:txn_1000000123:refund',
    entryKind: 'refund',
    refundOf: 'revenuecat:txn_1000000123',
    eventId: $id('revenue-refund'),
    timestamp: '2026-11-08T08:12:00Z',
);

$mira->revenue(
    amount: 1999.0,
    currency: 'USD',
    anonymousId: 'anon_7',
    subscription: ['id' => 'sub_7', 'interval' => 'year'],
    eventId: $id('revenue-float-amount'),
    timestamp: '2026-11-08T08:13:00Z',
);

$mira->touchpoint(
    kind: 'survey',
    userId: 'user_42',
    source: 'podcast',
    answer: 'The Elternzeit podcast',
    eventId: Mira::eventIdFor('survey:response_77'),
    timestamp: '2026-11-08T08:20:00Z',
);

$mira->touchpoint(
    kind: 'offline',
    userId: 'user_42',
    channel: 'paid_social',
    source: 'instagram',
    medium: 'paid',
    campaign: 'autumn_sale',
    content: 'carousel_b',
    campaignId: '120200001',
    adGroupId: '120200002',
    adId: '120200003',
    externalRef: 'hubspot:123',
    properties: ['booth' => 'B12'],
    eventId: $id('touchpoint-offline'),
    timestamp: '2026-11-08T08:21:00Z',
);

$mira->touchpoint(
    kind: 'code',
    anonymousId: 'anon_7',
    channel: 'affiliate',
    code: 'PARTNER20',
    eventId: $id('touchpoint-code'),
    timestamp: '2026-11-08T08:22:00Z',
);

// A scope fills in the user, consent and context for every event it sends.
$request = $mira->with(userId: 'user_42', consent: ['analytics' => true, 'marketing' => true], context: $browser);
$request->track(event: 'plan_changed', properties: ['from' => 'basic', 'to' => 'pro'], eventId: $id('scoped'), timestamp: '2026-11-08T08:30:00Z');

// An invalid location is dropped (and reported); the event still goes out.
$mira->track(
    event: 'store_searched',
    userId: 'user_42',
    eventId: $id('bad-location'),
    timestamp: '2026-11-08T08:31:00Z',
    consent: Consent::analyticsOnly(),
    context: ['location' => ['latitude' => 123.4, 'longitude' => 13.4]],
);

$mira->shutdown();

foreach ($problems as $problem) {
    if (! str_starts_with($problem, 'store_searched: location dropped')) {
        fwrite(STDERR, "unexpected problem: {$problem}\n");
        exit(1);
    }
}

echo '['.implode(",\n", array_column($transport->requests(), 'body'))."]\n";
