<?php

declare(strict_types=1);

use MiraFive\Event;

it('keeps the shared fixtures byte-identical to the protocol repository', function (string $file, string $sha256): void {
    expect(hash_file('sha256', dirname(__DIR__).'/Fixtures/'.$file))->toBe($sha256);
})->with([
    ['batch-id.cases.json', 'aa311a62830cb60bac8884d80f125bb21454e82fdc0a41582540d3eff8431dd0'],
    ['reserved-names.json', '1c8d9d3c111dd0de8c9b160786f7f11132b38d730924d7cdb4e9843f062faa84'],
]);

it('reserves exactly the protocol names', function (): void {
    expect(Event::RESERVED)->toBe(loadFixture('reserved-names.json')['names']);
});
