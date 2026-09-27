<?php

declare(strict_types=1);

namespace MiraFive;

use Psr\SimpleCache\CacheInterface;
use Throwable;

/**
 * @internal Short-lived marks for one source: in this process, and in the PSR-16 cache when one is configured so
 * that FPM workers share them. A cache that fails only costs a network request.
 */
final class Store
{
    private const int MAX_LOCAL = 10_000;

    private readonly string $prefix;

    /** @var array<string, array{0: int|null, 1: mixed}> expiry in epoch ms, and value */
    private array $local = [];

    public function __construct(private readonly ?CacheInterface $cache, string $host, string $key)
    {
        // No ids or key material in cache keys, and short enough for any PSR-16 implementation (64 characters).
        $this->prefix = 'mirafive.'.substr(hash('sha256', $host.' '.$key), 0, 12).'.';
    }

    public function get(string $name): mixed
    {
        $entry = $this->local[$name] ?? null;

        if ($entry !== null && ($entry[0] === null || $entry[0] > Clock::ms())) {
            return $entry[1];
        }

        unset($this->local[$name]);

        try {
            return $this->cache?->get($this->prefix.$name);
        } catch (Throwable) {
            return null;
        }
    }

    public function set(string $name, mixed $value, ?int $ttlSeconds = null): void
    {
        if (count($this->local) >= self::MAX_LOCAL) {
            array_shift($this->local);
        }

        $this->local[$name] = [$ttlSeconds === null ? null : Clock::ms() + $ttlSeconds * 1000, $value];

        try {
            $this->cache?->set($this->prefix.$name, $value, $ttlSeconds);
        } catch (Throwable) {
            // Kept in this process only.
        }
    }

    /** A name for a value that must not appear in a cache key, such as a user id. */
    public static function hashed(string $kind, mixed $value): string
    {
        return $kind.'.'.substr(hash('sha256', serialize($value)), 0, 24);
    }
}
