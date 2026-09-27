<?php

declare(strict_types=1);

use MiraFive\Event;
use MiraFive\Flags\Evaluator;

it('keeps the shared fixtures byte-identical to the protocol repository', function (string $file, string $sha256): void {
    expect(hash_file('sha256', dirname(__DIR__).'/Fixtures/'.$file))->toBe($sha256);
})->with([
    ['flag-hash.cases.json', 'dadad6aa56a74aa0788091ebaf44b5bc7d119303b3e2450e0cda4faea55569fe'],
    ['flag-eval.cases.json', 'a4cd7b9a38311a08d63b8c5953c0b7a808210f9bf5960a71642978e71235c6f6'],
    ['batch-id.cases.json', 'aa311a62830cb60bac8884d80f125bb21454e82fdc0a41582540d3eff8431dd0'],
    ['reserved-names.json', '1c8d9d3c111dd0de8c9b160786f7f11132b38d730924d7cdb4e9843f062faa84'],
]);

it('reserves exactly the protocol names', function (): void {
    expect(Event::RESERVED)->toBe(loadFixture('reserved-names.json')['names']);
});

it('treats exactly the fixture junk ids as no id at the fixture level', function (): void {
    $cases = loadFixture('flag-eval.cases.json');

    expect(Evaluator::JUNK)->toBe($cases['junk'])
        ->and(Evaluator::LEVEL)->toBe($cases['level']);
});
