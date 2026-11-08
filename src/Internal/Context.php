<?php

declare(strict_types=1);

namespace MiraFive\Internal;

use MiraFive\Mira;
use MiraFive\MiraError;
use stdClass;

/**
 * @internal The event's `context` on the wire. Only known fields with usable values are kept; an
 * invalid location would make the ingest reject the whole event, so only the location is dropped,
 * and reported.
 */
final class Context
{
    private const array PAGE = ['url', 'referrer', 'title'];

    private const array APP = ['name', 'version', 'build'];

    /**
     * @param  array<array-key, mixed>  $given  ip, userAgent, page, app, locale, timezone, location
     */
    public static function build(array $given, string $event, Reporter $reporter): stdClass
    {
        $context = new stdClass;
        $context->library = (object) ['name' => Mira::LIBRARY, 'version' => Mira::VERSION];

        foreach (['ip' => 'ip', 'userAgent' => 'user_agent', 'user_agent' => 'user_agent', 'locale' => 'locale', 'timezone' => 'timezone'] as $field => $wire) {
            $value = $given[$field] ?? null;

            if (is_string($value) && $value !== '') {
                $context->{$wire} = $value;
            }
        }

        foreach (['page' => self::PAGE, 'app' => self::APP] as $field => $keys) {
            $part = self::strings($given[$field] ?? null, $keys);

            if ($part !== null) {
                $context->{$field} = $part;
            }
        }

        $location = $given['location'] ?? null;

        if ($location !== null) {
            if (self::validLocation($location)) {
                /** @var array{latitude: int|float, longitude: int|float} $location */
                $context->location = (object) ['latitude' => $location['latitude'], 'longitude' => $location['longitude']];
            } else {
                $reporter->report(new MiraError("{$event}: location dropped (latitude -90..90, longitude -180..180)"));
            }
        }

        return $context;
    }

    /**
     * @param  list<string>  $keys
     */
    private static function strings(mixed $value, array $keys): ?stdClass
    {
        if (! is_array($value)) {
            return null;
        }

        $part = new stdClass;

        foreach ($keys as $key) {
            if (is_string($value[$key] ?? null) && $value[$key] !== '') {
                $part->{$key} = $value[$key];
            }
        }

        return get_object_vars($part) === [] ? null : $part;
    }

    private static function validLocation(mixed $value): bool
    {
        if (! is_array($value)) {
            return false;
        }

        $latitude = $value['latitude'] ?? null;
        $longitude = $value['longitude'] ?? null;

        return (is_int($latitude) || is_float($latitude)) && (is_int($longitude) || is_float($longitude))
            && is_finite((float) $latitude) && is_finite((float) $longitude)
            && abs($latitude) <= 90 && abs($longitude) <= 180;
    }
}
