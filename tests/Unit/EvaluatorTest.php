<?php

declare(strict_types=1);

use MiraFive\Flags\ErrorCode;
use MiraFive\Flags\Evaluator;
use MiraFive\Flags\Facts;
use MiraFive\Flags\Reason;

/**
 * Facts keep JSON objects as objects: `{}` is an object property, `[]` a list, as PHP callers can say too.
 */
function factsOf(stdClass $facts): Facts
{
    $properties = $facts->properties ?? null;
    $segments = $facts->segments ?? null;

    return Facts::fromArray([
        ...get_object_vars($facts),
        'properties' => $properties instanceof stdClass ? get_object_vars($properties) : [],
        'segments' => $segments instanceof stdClass ? get_object_vars($segments) : $segments,
    ]);
}

dataset('eval cases', function (): iterable {
    $file = json_decode((string) file_get_contents(dirname(__DIR__).'/Fixtures/flag-eval.cases.json'), flags: JSON_THROW_ON_ERROR);
    $cases = loadFixture('flag-eval.cases.json')['cases'] ?? [];

    foreach (is_array($cases) ? $cases : [] as $index => $case) {
        if (is_array($case) && $file instanceof stdClass && is_array($file->cases) && $file->cases[$index] instanceof stdClass) {
            yield (string) $case['name'] => [$case['flag'], $file->cases[$index]->facts, $case['expect']];
        }
    }
});

it('passes the shared evaluation case', function (array $flag, stdClass $facts, array $expect): void {
    expect(Evaluator::evaluate($flag, factsOf($facts))->toArray())->toBe($expect);
})->with('eval cases');

it('answers UNSUPPORTED for entries it cannot read', function (array $flag): void {
    expect(Evaluator::evaluate($flag, new Facts(id: 'a'))->errorCode)->toBe(ErrorCode::Unsupported);
})->with([
    'no rules' => [['s' => 'k3v9x0q2m7ta', 'u' => 'b', 'd' => 'off']],
    'unknown unit' => [['s' => 'k3v9x0q2m7ta', 'u' => 'x', 'd' => 'off', 'r' => []]],
    'rule not an object' => [['s' => 'k3v9x0q2m7ta', 'u' => 'b', 'd' => 'off', 'r' => ['on']]],
    'weights not pairs' => [['s' => 'k3v9x0q2m7ta', 'u' => 'b', 'd' => 'off', 'r' => [['w' => [['on']]]]]],
    'share not a number' => [['s' => 'k3v9x0q2m7ta', 'u' => 'b', 'd' => 'off', 'r' => [['sh' => '5000']]]],
]);

it('ignores experiment and value keys', function (): void {
    $flag = ['s' => 'k3v9x0q2m7ta', 't' => 'm', 'u' => 'p', 'd' => 'a', 'r' => [['w' => [['a', 5000], ['b', 5000]]]]];
    $facts = new Facts(userId: 'user-42');

    expect(Evaluator::evaluate([...$flag, 'e' => 'r', 'c' => 's', 'p' => ['a' => 1], 'w' => 1], $facts))
        ->toEqual(Evaluator::evaluate($flag, $facts))
        ->and(Evaluator::evaluate($flag, $facts)->reason)->toBe(Reason::Split);
});
