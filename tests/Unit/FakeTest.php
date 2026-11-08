<?php

declare(strict_types=1);

use MiraFive\Http\Response;
use MiraFive\Mira;
use MiraFive\Testing\MiraFake;
use PHPUnit\Framework\AssertionFailedError;

it('records what the code sent and asserts on it', function (): void {
    $fake = MiraFake::create();
    $fake->mira->track('signup', userId: 'u1', properties: ['plan' => 'pro']);
    $fake->mira->identify('u1', traits: ['email' => 'ada@example.com']);
    $fake->mira->group('acme', userId: 'u1');
    $fake->mira->revenue(4900, 'EUR', userId: 'u1', entryId: 'stripe:in_1');
    $fake->mira->touchpoint('survey', userId: 'u1', source: 'podcast');

    $fake->assertTracked('signup');
    $fake->assertTracked('signup', 1);
    $fake->assertTracked('signup', fn (array $event): bool => $event['properties']['plan'] === 'pro');
    $fake->assertNotTracked('login');
    $fake->assertIdentified('u1');
    $fake->assertIdentified('u1', fn (array $event): bool => $event['traits']['email'] === 'ada@example.com');
    $fake->assertGrouped('acme');
    $fake->assertRevenue(fn (array $event): bool => $event['revenue']['amount'] === 4900);
    $fake->assertTouchpoint(1);
    $fake->assertNoErrors();

    expect($fake->events())->toHaveCount(5)
        ->and($fake->transport->requests()[0]['url'])->toBe('https://events.mirafive.io/v1/batch');
});

it('fails assertions with a clear message', function (): void {
    $fake = MiraFake::create();
    $fake->mira->track('signup');

    expect(fn () => $fake->assertTracked('login'))->toThrow(AssertionFailedError::class, 'The event "login" was not sent.')
        ->and(fn () => $fake->assertTracked('signup', 2))->toThrow(AssertionFailedError::class, 'The event "signup" was sent 1 times, not 2.')
        ->and(fn () => $fake->assertNothingTracked())->toThrow(AssertionFailedError::class, 'Events were sent: signup.')
        ->and(fn () => $fake->assertIdentified('u1'))->toThrow(AssertionFailedError::class, 'The user "u1" was not identified.');
});

it('names the problems the client reported', function (): void {
    $fake = MiraFake::create();
    $fake->mira->revenue(19.99, 'EUR');

    $fake->assertNothingTracked();
    expect(fn () => $fake->assertNoErrors())->toThrow(AssertionFailedError::class, 'revenue: amount must be an integer in minor units');
});

it('counts a retried batch once and forgets on clear', function (): void {
    $fake = MiraFake::create();
    $fake->transport->answerNext(new Response(503));
    $fake->mira->track('a', eventId: Mira::eventIdFor('a'));

    $fake->assertTracked('a', 1);
    $fake->clear();
    $fake->assertNothingTracked();
});
