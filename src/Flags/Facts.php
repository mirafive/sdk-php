<?php

declare(strict_types=1);

namespace MiraFive\Flags;

/** What a flag is evaluated against: the units, the caller's properties and the unit's segments. */
final readonly class Facts
{
    public const string PENDING = 'pending';

    public const string UNAVAILABLE = 'unavailable';

    /**
     * @param  array<array-key, mixed>  $properties
     * @param  Membership|'pending'|'unavailable'|null  $segments
     */
    public function __construct(
        public ?string $id = null,
        public ?string $userId = null,
        public array $properties = [],
        public Membership|string|null $segments = null,
    ) {}

    /**
     * The wire form, as the shared fixtures write it.
     *
     * @param  array<array-key, mixed>  $facts
     */
    public static function fromArray(array $facts): self
    {
        $segments = $facts['segments'] ?? null;

        return new self(
            id: is_string($facts['id'] ?? null) ? $facts['id'] : null,
            userId: is_string($facts['userId'] ?? null) ? $facts['userId'] : null,
            properties: is_array($facts['properties'] ?? null) ? $facts['properties'] : [],
            segments: match (true) {
                is_array($segments) => new Membership(self::strings($segments['in'] ?? []), self::strings($segments['unavailable'] ?? [])),
                $segments === self::PENDING, $segments === self::UNAVAILABLE => $segments,
                default => null,
            },
        );
    }

    public function withoutId(): self
    {
        return new self(null, $this->userId, $this->properties, $this->segments);
    }

    /**
     * @return list<string>
     */
    private static function strings(mixed $values): array
    {
        return is_array($values) ? array_values(array_filter($values, is_string(...))) : [];
    }
}
