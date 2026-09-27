<?php

declare(strict_types=1);

use MiraFive\BatchId;

it('derives the protocol batch id from an idempotency key', function (): void {
    $cases = loadFixture('batch-id.cases.json')['cases'] ?? [];

    foreach (is_array($cases) ? $cases : [] as $case) {
        expect(is_array($case) ? BatchId::fromIdempotencyKey((string) $case['key']) : null)->toBe(is_array($case) ? $case['batch'] : '');
    }
});

it('draws random batch ids as UUIDv4', function (): void {
    expect(BatchId::random())->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/')
        ->and(BatchId::random())->not->toBe(BatchId::random());
});
