<?php

declare(strict_types=1);

namespace MiraFive\Internal;

use Psr\SimpleCache\CacheInterface;
use Throwable;

/**
 * @internal Short-lived marks for one key and host: in this process, and in the PSR-16 cache when
 * one is configured, so that PHP-FPM workers share them. A cache that fails only costs a request.
 */
final class Store
{
    private readonly string $prefix;

    /** @var array<string, array{0: int, 1: mixed}> expiry in epoch ms, and value */
    private array $local = [];

    public function __construct(private readonly ?CacheInterface $cache, string $host, string $key)
    {
        // No key material in cache keys, and short enough for any PSR-16 implementation.
        $this->prefix = 'mirafive.'.substr(hash('sha256', $host.' '.$key), 0, 12).'.';
    }

    public function get(string $name): mixed
    {
        $entry = $this->local[$name] ?? null;

        if ($entry !== null && $entry[0] > Clock::ms()) {
            return $entry[1];
        }

        unset($this->local[$name]);

        try {
            return $this->cache?->get($this->prefix.$name);
        } catch (Throwable) {
            return null;
        }
    }

    public function set(string $name, mixed $value, int $ttlSeconds): void
    {
        $this->local[$name] = [Clock::ms() + $ttlSeconds * 1000, $value];

        try {
            $this->cache?->set($this->prefix.$name, $value, $ttlSeconds);
        } catch (Throwable) {
            // Kept in this process only.
        }
    }
}
