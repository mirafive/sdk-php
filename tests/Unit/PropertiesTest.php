<?php

declare(strict_types=1);

use MiraFive\Internal\Properties;

function cleaned(mixed $input): string
{
    return (string) json_encode(Properties::clean($input), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

enum Plan: string
{
    case Pro = 'pro';
}

// The expected JSON is what the Node SDK's cleanProperties gives for the same input.
it('flattens nested values to dot keys up to depth 3, like the Node SDK', function (): void {
    expect(cleaned(['a' => ['b' => ['c' => ['d' => ['e' => 1]]]], 'plan' => ['name' => 'pro', 'seats' => 3]]))
        ->toBe('{"plan.name":"pro","plan.seats":3}');
});

it('keeps scalars in lists and drops the rest', function (): void {
    expect(cleaned(['arr' => [1, 'x', null, ['o' => 1], true, [2]], 'empty' => []]))
        ->toBe('{"arr":[1,"x",true],"empty":[]}');
});

it('turns non-finite numbers into null', function (): void {
    expect(cleaned(['n' => NAN, 'inf' => INF, 'f' => 1.5, 'i' => 7]))->toBe('{"n":null,"inf":null,"f":1.5,"i":7}');
});

it('encodes no properties as an object, never a list', function (): void {
    expect(cleaned([]))->toBe('{}')
        ->and(cleaned(['first', 'second']))->toBe('{"0":"first","1":"second"}')
        ->and(cleaned('not an array'))->toBe('{}');
});

it('applies the wire limits', function (): void {
    $many = Properties::clean(array_combine(array_map(static fn (int $i): string => "k{$i}", range(0, 69)), range(0, 69)));
    $long = Properties::clean(['s' => str_repeat('€', 3000), str_repeat('k', 150) => 1, '' => 'dropped']);
    $astral = Properties::clean(['s' => str_repeat('😀', 1500)]);
    $list = Properties::clean(['l' => range(1, 60)]);

    expect(get_object_vars($many))->toHaveCount(64)
        ->and(mb_strlen($long->s))->toBe(2048)
        ->and(array_keys(get_object_vars($long)))->toBe(['s', str_repeat('k', 100)])
        // 2048 UTF-16 units, as JavaScript counts them: 1024 emoji.
        ->and(mb_strlen($astral->s))->toBe(1024)
        ->and($list->l)->toHaveCount(50);
});

it('turns dates, enums and objects into wire values', function (): void {
    $object = new stdClass;
    $object->name = 'Acme';

    expect(cleaned([
        'at' => new DateTimeImmutable('2026-11-08T10:00:00+01:00'),
        'plan' => Plan::Pro,
        'company' => $object,
        'dates' => [new DateTimeImmutable('2026-01-01T00:00:00Z')],
    ]))->toBe('{"at":"2026-11-08T09:00:00.000Z","plan":"pro","company.name":"Acme","dates":["2026-01-01T00:00:00.000Z"]}');
});

it('repairs invalid UTF-8 instead of failing', function (): void {
    expect(cleaned(['s' => "ok\xC3"]))->toBe('{"s":"ok�"}');
});
