<?php

declare(strict_types=1);

namespace MiraFive;

use InvalidArgumentException;
use JsonException;
use stdClass;

/** @internal The collector's property limits (PROTOCOL §3), checked before the event joins a batch. */
final class Properties
{
    public const int MAX_LEAVES = 64;

    public const int MAX_DEPTH = 5;

    public const int MAX_KEY_LENGTH = 128;

    public const int MAX_BYTES = 32_768;

    /**
     * @return array<string, mixed>
     */
    public static function check(mixed $properties): array
    {
        if (! is_array($properties) || ($properties !== [] && array_is_list($properties))) {
            throw new InvalidArgumentException('properties is an array keyed by name.');
        }

        $leaves = 0;
        self::walk($properties, 1, $leaves);

        // Measured exactly as the collector measures (plain json_encode), so unicode and slashes count escaped.
        try {
            $bytes = strlen(json_encode($properties, JSON_THROW_ON_ERROR));
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('properties cannot be encoded as JSON: '.$exception->getMessage(), 0, $exception);
        }

        if ($bytes > self::MAX_BYTES) {
            throw new InvalidArgumentException('properties may not encode to more than '.self::MAX_BYTES.' bytes.');
        }

        /** @var array<string, mixed> $properties */
        return $properties;
    }

    /**
     * Lists and empty arrays or objects are one leaf each, as the collector counts them. A stdClass is an object.
     *
     * @param  array<array-key, mixed>  $properties
     */
    private static function walk(array $properties, int $depth, int &$leaves): void
    {
        foreach ($properties as $key => $value) {
            $value = $value instanceof stdClass ? get_object_vars($value) : $value;

            if (Text::length((string) $key) > self::MAX_KEY_LENGTH) {
                throw new InvalidArgumentException('Property keys are at most '.self::MAX_KEY_LENGTH.' characters.');
            }

            if (is_array($value) && $value !== [] && ! array_is_list($value)) {
                if ($depth === self::MAX_DEPTH) {
                    throw new InvalidArgumentException('properties may not nest deeper than '.self::MAX_DEPTH.' levels.');
                }

                self::walk($value, $depth + 1, $leaves);

                continue;
            }

            if (! is_array($value) && ! is_scalar($value) && $value !== null) {
                throw new InvalidArgumentException('Property values are scalars, null or arrays.');
            }

            if (++$leaves > self::MAX_LEAVES) {
                throw new InvalidArgumentException('properties may not carry more than '.self::MAX_LEAVES.' values.');
            }
        }
    }
}
