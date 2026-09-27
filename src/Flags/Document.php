<?php

declare(strict_types=1);

namespace MiraFive\Flags;

use stdClass;

/**
 * A server flag document. Variant values are also kept as decoded objects, so a bootstrap writes `{}` back as `{}`.
 *
 * @internal
 */
final readonly class Document
{
    /**
     * @param  array<string, array<array-key, mixed>>  $flags
     * @param  array<string, array<array-key, mixed>>  $values
     */
    private function __construct(
        public string $json,
        public int $at,
        public array $flags,
        private array $values,
    ) {}

    public static function parse(string $json): ?self
    {
        $document = json_decode($json, true);
        $objects = json_decode($json);

        if (! is_array($document) || ($document['v'] ?? null) !== 1 || ! is_array($document['flags'] ?? null) || ! $objects instanceof stdClass || ! $objects->flags instanceof stdClass) {
            return null;
        }

        $flags = [];
        $values = [];

        foreach ($document['flags'] as $key => $flag) {
            if (! is_array($flag)) {
                continue;
            }

            $key = (string) $key;
            $flags[$key] = $flag;
            $entry = $objects->flags->{$key} ?? null;
            $p = $entry instanceof stdClass ? ($entry->p ?? null) : null;
            $values[$key] = $p instanceof stdClass ? get_object_vars($p) : [];
        }

        return new self($json, is_int($document['at'] ?? null) ? $document['at'] : 0, $flags, $values);
    }

    /**
     * @return array{0: bool, 1: mixed} whether the variant has a value, and the value with objects kept as objects
     */
    public function value(string $key, string $variant): array
    {
        return array_key_exists($variant, $this->values[$key] ?? [])
            ? [true, $this->values[$key][$variant]]
            : [false, null];
    }

    public function hasSegments(): bool
    {
        foreach ($this->flags as $flag) {
            if (self::refs($flag) !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<array-key, mixed>  $flag
     * @return list<string>
     */
    public static function refs(array $flag): array
    {
        $refs = [];

        foreach (is_array($flag['r'] ?? null) ? $flag['r'] : [] as $rule) {
            foreach (is_array($rule) && is_array($rule['if'] ?? null) ? $rule['if'] : [] as $condition) {
                if (is_array($condition) && ($condition[0] ?? null) === 's' && is_string($condition[1] ?? null)) {
                    $refs[] = $condition[1];
                }
            }
        }

        return $refs;
    }
}
