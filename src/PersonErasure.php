<?php

declare(strict_types=1);

namespace MiraFive;

/**
 * The proof that `Mira::deletePerson()` returns: the erasure request as MIRA FIVE's People page
 * records it, and the rows deleted per table. Revenue stays in the totals, without the person.
 */
final readonly class PersonErasure
{
    /**
     * @param  string  $requestId  the deletion request, listed in the project's privacy log
     * @param  array<string, int>  $deleted  rows deleted per table
     * @param  int  $revenueKept  revenue events kept without the person, so totals stay correct
     * @param  string  $completedAt  ISO 8601
     */
    public function __construct(
        public string $requestId,
        public array $deleted,
        public int $revenueKept,
        public string $completedAt,
    ) {}

    /** Null when the body is not a MIRA FIVE erasure proof. */
    public static function fromJson(mixed $body): ?self
    {
        if (! is_array($body) || ! is_string($body['request_id'] ?? null) || ! is_array($body['deleted'] ?? null)) {
            return null;
        }

        $deleted = [];

        foreach ($body['deleted'] as $table => $rows) {
            if (is_string($table) && is_int($rows)) {
                $deleted[$table] = $rows;
            }
        }

        return new self(
            $body['request_id'],
            $deleted,
            is_int($body['revenue_kept'] ?? null) ? $body['revenue_kept'] : 0,
            is_string($body['completed_at'] ?? null) ? $body['completed_at'] : '',
        );
    }
}
