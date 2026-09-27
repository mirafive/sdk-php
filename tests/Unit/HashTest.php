<?php

declare(strict_types=1);

use MiraFive\Flags\Hash;

/**
 * @return list<array<string, mixed>>
 */
function hashCases(string $kind): array
{
    $cases = loadFixture('flag-hash.cases.json')[$kind] ?? [];

    return is_array($cases) ? array_values(array_filter($cases, is_array(...))) : [];
}

it('computes fnv1a32 for every fixture input', function (): void {
    foreach (hashCases('fnv1a32') as $case) {
        expect(Hash::fnv1a32((string) $case['input']))->toBe($case['hash'], (string) $case['input']);
    }
});

it('computes the bucket for every fixture unit', function (): void {
    foreach (hashCases('bucket') as $case) {
        expect(Hash::bucket((string) $case['seed'], (string) $case['salt'], (string) $case['unit']))->toBe($case['bucket'], (string) $case['unit']);
    }
});

it('covers the golden values', function (): void {
    expect(Hash::fnv1a32('abc'))->toBe(440920331)
        ->and(Hash::fnv1a32('müller'))->toBe(1392138076)
        ->and(Hash::bucket('3f9a1c0b7e2d', Hash::SHARE, '0199a3f2-7c1e-7a4b-9f00-1b2c3d4e5f60'))->toBe(7227)
        ->and(Hash::bucket('3f9a1c0b7e2d', Hash::VARIANT, 'user-42'))->toBe(7627);
});
