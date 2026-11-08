<?php

declare(strict_types=1);

use MiraFive\Mira;
use MiraFive\Uuid;

// Computed with the Node SDK (`bun -e` against sdks/node/src/index.ts, eventIdFor), so PHP and Node
// give byte-identical ids and cross-language reports of one fact dedupe.
dataset('node vectors', [
    'order' => ['order:123:paid', 'dc7e1871-544e-8ba6-bf35-0db3d8768abf'],
    'survey' => ['survey:response_77', '65abb77f-67f1-8ada-9aa0-0ab0e47adedd'],
    'empty' => ['', 'e3b0c442-98fc-8c14-9afb-f4c8996fb924'],
    'unicode' => ['ümlaut:straße:€', '9ddd908f-34b7-8261-a6ed-fff983ec0e84'],
    'long' => [str_repeat('a', 1000), '41edece4-2d63-88d9-bf51-5a9ba6932e1c'],
]);

it('gives the same id as the Node SDK for the same key', function (string $key, string $expected): void {
    expect(Mira::eventIdFor($key))->toBe($expected);
})->with('node vectors');

it('makes UUIDv8 ids', function (): void {
    expect(Mira::eventIdFor('anything'))->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-8[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/');
});

it('makes time-ordered UUIDv7 ids, monotonic within one millisecond', function (): void {
    $ids = array_map(static fn (): string => Uuid::v7(4_102_444_800_000), range(1, 500));

    expect($ids[0])->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/')
        ->and(substr($ids[0], 0, 13))->toBe('03bb2cc3-d800')
        ->and($ids)->toBe(array_values(array_unique($ids)));

    $sorted = $ids;
    sort($sorted);
    expect($sorted)->toBe($ids);
});
