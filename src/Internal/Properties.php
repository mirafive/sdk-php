<?php

declare(strict_types=1);

namespace MiraFive\Internal;

use BackedEnum;
use DateTimeInterface;
use JsonSerializable;
use stdClass;
use Throwable;
use UnitEnum;

/**
 * @internal The Node SDK's `cleanProperties`, in PHP. Whatever the app passes becomes properties the
 * ingest accepts: nested arrays and objects are flattened to dot keys (`plan.name`) up to depth 3,
 * dates become ISO strings, and every limit of the wire schema is applied here. The ingest rejects a
 * whole event on a violation, so trimming here keeps the rest of the data.
 *
 * A PHP list (`[1, 2]`) is an array of scalars; an array with string or non-sequential keys is an
 * object. The result is a stdClass, so that no properties still encode as `{}`, never `[]`.
 */
final class Properties
{
    public const int MAX_KEYS = 64;

    public const int MAX_STRING = 2048;

    public const int MAX_KEY = 100;

    public const int MAX_ARRAY = 50;

    public const int MAX_DEPTH = 3;

    private int $count = 0;

    private stdClass $output;

    private function __construct()
    {
        $this->output = new stdClass;
    }

    public static function clean(mixed $input): stdClass
    {
        $cleaner = new self;
        $cleaner->walk($input, '', 0);

        return $cleaner->output;
    }

    /** A value as a scalar property, or null when it is none (an array, an object to flatten). */
    public static function scalar(mixed $value): ?ScalarValue
    {
        return match (true) {
            $value === null, is_bool($value), is_int($value) => new ScalarValue($value),
            is_string($value) => new ScalarValue(Text::cut($value, self::MAX_STRING)),
            is_float($value) => new ScalarValue(is_finite($value) ? $value : null),
            $value instanceof DateTimeInterface => new ScalarValue(Time::iso($value)),
            $value instanceof BackedEnum => self::scalar($value->value),
            $value instanceof UnitEnum => new ScalarValue($value->name),
            default => null,
        };
    }

    private function walk(mixed $value, string $prefix, int $depth): void
    {
        foreach (self::entries($value) ?? [] as $rawKey => $item) {
            if ($this->count >= self::MAX_KEYS) {
                return;
            }

            $key = Text::cut($prefix === '' ? (string) $rawKey : $prefix.'.'.$rawKey, self::MAX_KEY);

            if ($key === '') {
                continue;
            }

            if (is_array($item) && array_is_list($item)) {
                $list = [];

                foreach ($item as $entry) {
                    $flat = self::scalar($entry);

                    if ($flat !== null && $flat->value !== null) {
                        $list[] = $flat->value;
                    }
                }

                $this->output->{$key} = array_slice($list, 0, self::MAX_ARRAY);
                $this->count++;

                continue;
            }

            $flat = self::scalar($item);

            if ($flat !== null) {
                $this->output->{$key} = $flat->value;
                $this->count++;
            } elseif ($depth < self::MAX_DEPTH) {
                $this->walk($item, $key, $depth + 1);
            }
        }
    }

    /**
     * The key-value pairs of an array or object, or null for anything else.
     *
     * @return array<array-key, mixed>|null
     */
    private static function entries(mixed $value): ?array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_object($value) || $value instanceof DateTimeInterface || $value instanceof UnitEnum) {
            return null;
        }

        if ($value instanceof JsonSerializable) {
            try {
                $data = $value->jsonSerialize();
            } catch (Throwable) {
                return null;
            }

            return is_array($data) ? $data : (is_object($data) ? get_object_vars($data) : null);
        }

        return get_object_vars($value);
    }
}
