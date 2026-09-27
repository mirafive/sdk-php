<?php

declare(strict_types=1);

namespace MiraFive\Flags;

use MiraFive\Text;

/**
 * Pure flag evaluation, identical in every MIRA FIVE SDK and pinned by the shared fixtures (FLAGS.md).
 * An entry this engine cannot read is UNSUPPORTED, never an exception.
 */
final class Evaluator
{
    /** The feature level this engine understands; a flag that needs more is UNSUPPORTED. */
    public const int LEVEL = 1;

    /** In UTF-16 code units, as JavaScript counts a string's length. Longer ids are no id, never truncated. */
    public const int MAX_ID_LENGTH = 256;

    /** What a broken integration sends for every visitor; hashing it would put them all in one bucket. */
    public const array JUNK = [
        'undefined', 'null', 'none', 'nan', '0', 'true', 'false', 'anonymous', 'guest', 'id',
        'email', 'distinct_id', 'distinctid', 'not_authenticated', '[object object]',
    ];

    /**
     * @param  array<array-key, mixed>  $flag
     */
    public static function evaluate(array $flag, Facts $facts): Evaluation
    {
        $rules = self::rules($flag);

        if ($rules === null || ! is_string($flag['s']) || ! is_string($flag['d']) || ($flag['need'] ?? 1) > self::LEVEL) {
            return Evaluation::failed(ErrorCode::Unsupported);
        }

        if (! empty($flag['off'])) {
            return Evaluation::decided($flag['d'], Reason::Disabled);
        }

        $unit = self::usable($flag['u'] === 'p' ? $facts->userId : $facts->id);

        foreach ($rules as $index => $rule) {
            $conditions = $rule['if'] ?? null;

            if ($conditions !== null && $facts->segments === Facts::PENDING && self::any($conditions, self::isSegmentCondition(...))) {
                return Evaluation::failed(ErrorCode::NotReady);
            }

            if ($conditions !== null && ! self::all($conditions, fn (mixed $condition): bool => self::holds($condition, $facts))) {
                continue;
            }

            if (isset($rule['x'])) {
                return Evaluation::decided($rule['x'], $conditions === null ? Reason::Static : Reason::TargetingMatch, $index);
            }

            if ($unit === null || Hash::bucket($flag['s'], Hash::SHARE, $unit) >= ($rule['sh'] ?? Hash::BUCKETS)) {
                return Evaluation::decided($flag['d'], Reason::Default, $index);
            }

            $bucket = Hash::bucket($flag['s'], Hash::VARIANT, $unit);

            foreach ($rule['w'] ?? [] as [$variant, $weight]) {
                $bucket -= $weight;

                if ($bucket < 0) {
                    return Evaluation::decided($variant, Reason::Split, $index);
                }
            }

            return Evaluation::decided($flag['d'], Reason::Default, $index);
        }

        return Evaluation::decided($flag['d'], Reason::Default);
    }

    /** The id to hash, unchanged, or null when it is junk, blank or too long. */
    public static function usable(mixed $id): ?string
    {
        if (! is_string($id) || self::tooLong($id)) {
            return null;
        }

        // Quoted too: a template that stringifies a missing value sends '"undefined"'. Only ASCII is lowered.
        $bare = trim(strtr($id, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz'), " \t\n\r\"'");

        return $bare === '' || in_array($bare, self::JUNK, true) ? null : $id;
    }

    /**
     * The rules in a shape this engine can run, or null. A rule may not mix a fixed variant with a split.
     *
     * @param  array<array-key, mixed>  $flag
     * @return list<array{if?: list<mixed>, x?: string, sh?: int, w?: list<array{0: string, 1: int}>}>|null
     */
    private static function rules(array $flag): ?array
    {
        $rules = $flag['r'] ?? null;

        if (! is_array($rules) || ! array_is_list($rules) || ! array_key_exists('s', $flag) || ! array_key_exists('d', $flag) || ! in_array($flag['u'] ?? null, ['b', 'p'], true)) {
            return null;
        }

        foreach ($rules as $rule) {
            if (! is_array($rule)) {
                return null;
            }

            $fixed = array_key_exists('x', $rule);
            $split = array_key_exists('sh', $rule) || array_key_exists('w', $rule);

            if (($fixed && ($split || ! is_string($rule['x'])))
                || (isset($rule['if']) && ! (is_array($rule['if']) && array_is_list($rule['if'])))
                || (isset($rule['sh']) && ! is_int($rule['sh']))
                || (isset($rule['w']) && ! self::isWeights($rule['w']))) {
                return null;
            }
        }

        /** @var list<array{if?: list<mixed>, x?: string, sh?: int, w?: list<array{0: string, 1: int}>}> $rules */
        return $rules;
    }

    private static function isWeights(mixed $weights): bool
    {
        return is_array($weights) && array_is_list($weights) && self::all($weights, fn (mixed $pair): bool => is_array($pair)
            && array_is_list($pair) && count($pair) === 2 && is_string($pair[0]) && is_int($pair[1]));
    }

    private static function tooLong(string $id): bool
    {
        return strlen($id) > self::MAX_ID_LENGTH && Text::length($id) > self::MAX_ID_LENGTH;
    }

    private static function isSegmentCondition(mixed $condition): bool
    {
        return is_array($condition) && ($condition[0] ?? null) === 's';
    }

    private static function holds(mixed $condition, Facts $facts): bool
    {
        if (! is_array($condition) || ! is_string($condition[1] ?? null)) {
            return false;
        }

        return match ($condition[0] ?? null) {
            'p' => is_string($condition[2] ?? null) && self::propertyHolds($facts->properties[$condition[1]] ?? null, $condition[2], $condition[3] ?? null),
            's' => self::segmentHolds($condition[1], ($condition[2] ?? null) === 1, $facts),
            default => false,
        };
    }

    /** Neither "in" nor "not in" holds while membership is unknown. */
    private static function segmentHolds(string $ref, bool $negated, Facts $facts): bool
    {
        $in = $facts->segments instanceof Membership ? $facts->segments->contains($ref) : null;

        return $in !== null && $in !== $negated;
    }

    /** A null property is missing, like an absent one. */
    private static function propertyHolds(mixed $property, string $operator, mixed $expected): bool
    {
        if ($operator === 'unset' || $property === null) {
            return $operator === 'unset' && $property === null;
        }

        $strings = self::isList($expected) ? array_values(array_filter($expected, is_string(...))) : [];

        return match ($operator) {
            'set' => true,
            'is' => self::isList($expected) && self::matchesAny($property, $expected),
            'not' => self::isList($expected) && (self::isScalar($property) || self::isList($property)) && ! self::matchesAny($property, $expected),
            'has' => is_string($property) && self::any($strings, fn (string $needle): bool => str_contains($property, $needle)),
            'nhas' => is_string($property) && self::isList($expected) && ! self::any($strings, fn (string $needle): bool => str_contains($property, $needle)),
            'pre' => is_string($property) && self::any($strings, fn (string $prefix): bool => str_starts_with($property, $prefix)),
            'gt' => self::isNumber($property) && self::isNumber($expected) && (float) $property > (float) $expected,
            'lt' => self::isNumber($property) && self::isNumber($expected) && (float) $property < (float) $expected,
            default => false,
        };
    }

    /**
     * @param  list<mixed>  $expected
     */
    private static function matchesAny(mixed $property, array $expected): bool
    {
        $values = self::isList($property) ? $property : [$property];

        return self::any($values, fn (mixed $value): bool => self::any($expected, fn (mixed $listed): bool => self::same($value, $listed)));
    }

    private static function same(mixed $value, mixed $listed): bool
    {
        return match (true) {
            is_string($value) && is_string($listed), is_bool($value) && is_bool($listed) => $value === $listed,
            self::isNumber($value) && self::isNumber($listed) => (float) $value === (float) $listed,
            is_string($value) && self::isNumber($listed) => self::isDecimal($value) && (float) $value === (float) $listed,
            self::isNumber($value) && is_string($listed) => self::isDecimal($listed) && (float) $value === (float) $listed,
            default => false,
        };
    }

    private static function isDecimal(string $value): bool
    {
        return preg_match('/^-?(0|[1-9][0-9]*)(\.[0-9]+)?\z/', $value) === 1;
    }

    /**
     * @phpstan-assert-if-true int|float $value
     */
    private static function isNumber(mixed $value): bool
    {
        return is_int($value) || is_float($value);
    }

    private static function isScalar(mixed $value): bool
    {
        return is_string($value) || is_bool($value) || self::isNumber($value);
    }

    /**
     * @phpstan-assert-if-true list<mixed> $value
     */
    private static function isList(mixed $value): bool
    {
        return is_array($value) && array_is_list($value);
    }

    /**
     * array_any() arrived in PHP 8.4.
     *
     * @template T
     *
     * @param  array<T>  $values
     * @param  callable(T): bool  $test
     */
    private static function any(array $values, callable $test): bool
    {
        foreach ($values as $value) {
            if ($test($value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @template T
     *
     * @param  array<T>  $values
     * @param  callable(T): bool  $test
     */
    private static function all(array $values, callable $test): bool
    {
        return ! self::any($values, fn (mixed $value): bool => ! $test($value));
    }
}
