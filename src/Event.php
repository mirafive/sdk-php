<?php

declare(strict_types=1);

namespace MiraFive;

use DateTimeInterface;
use InvalidArgumentException;

/**
 * @internal Turns caller input into a wire event (PROTOCOL §3), refusing what the collector would refuse: one bad
 * event costs the whole batch it travels in.
 */
final class Event
{
    public const array RESERVED = ['$pageview', '$autocapture', '$identify', '$search', '$install_check', '$exposure'];

    private const array FIELDS = ['name', 'id', 'time', 'userId', 'anonymousId', 'sessionId', 'properties', 'page'];

    private const array PAGE_LIMITS = ['url' => 2048, 'title' => 512, 'referrer' => 2048];

    private const string UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/i';

    /**
     * @param  array<array-key, mixed>  $input
     * @return array<string, mixed>
     */
    public static function wire(array $input, Mode $mode, int $nowMs): array
    {
        $unknown = array_diff(array_map(strval(...), array_keys($input)), self::FIELDS);

        if ($unknown !== []) {
            throw new InvalidArgumentException('Unknown event field: '.implode(', ', $unknown).'.');
        }

        $event = ['name' => self::name($input['name'] ?? null), 'time' => self::time($input['time'] ?? null, $nowMs)];

        if (isset($input['id'])) {
            $event['id'] = self::uuid('id', $input['id']);
        }

        $identifiers = array_filter([
            'anonymousId' => self::identifier('anonymousId', $input['anonymousId'] ?? null),
            'userId' => self::identifier('userId', $input['userId'] ?? null),
            'sessionId' => isset($input['sessionId']) ? self::uuid('sessionId', $input['sessionId']) : null,
        ], fn (?string $value): bool => $value !== null);

        if ($identifiers !== [] && $mode === Mode::Consentless) {
            throw new InvalidArgumentException('A consentless client may not send '.implode(', ', array_keys($identifiers)).'.');
        }

        if (isset($input['page'])) {
            $event['page'] = self::page($input['page']);
        }

        $properties = Properties::check($input['properties'] ?? []);

        if ($properties !== []) {
            $event['properties'] = $properties;
        }

        return [...$event, ...$identifiers];
    }

    public static function time(mixed $time, int $nowMs): int
    {
        return match (true) {
            $time === null => $nowMs,
            is_int($time) => $time,
            $time instanceof DateTimeInterface => (int) $time->format('Uv'),
            default => throw new InvalidArgumentException('An event time is a DateTimeInterface or epoch milliseconds.'),
        };
    }

    private static function name(mixed $name): string
    {
        if (! is_string($name) || $name === '' || trim($name) !== $name || Text::length($name) > 128) {
            throw new InvalidArgumentException('An event name is 1–128 characters without surrounding whitespace.');
        }

        if (str_starts_with($name, '$') && ! in_array($name, self::RESERVED, true)) {
            throw new InvalidArgumentException("Names starting with \$ are reserved; {$name} is not one of them.");
        }

        return $name;
    }

    private static function identifier(string $field, mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value) || trim($value) === '' || Text::length($value) > 256) {
            throw new InvalidArgumentException("{$field} is a non-blank string of at most 256 characters.");
        }

        return $value;
    }

    private static function uuid(string $field, mixed $value): string
    {
        if (! is_string($value) || preg_match(self::UUID, $value) !== 1) {
            throw new InvalidArgumentException("{$field} must be a UUID.");
        }

        return $value;
    }

    /**
     * @return array<string, string>
     */
    private static function page(mixed $page): array
    {
        if (! is_array($page)) {
            throw new InvalidArgumentException('page is an array with url, title and referrer.');
        }

        $clean = [];

        foreach (self::PAGE_LIMITS as $field => $max) {
            $value = $page[$field] ?? null;

            if ($value === null) {
                continue;
            }

            if (! is_string($value)) {
                throw new InvalidArgumentException("page.{$field} must be a string.");
            }

            $clean[$field] = Text::clamp($value, $max);
        }

        return $clean;
    }
}
