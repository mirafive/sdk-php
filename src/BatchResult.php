<?php

declare(strict_types=1);

namespace MiraFive;

/** The ingest's answer to a batch: per-event problems are listed here, never thrown. */
final readonly class BatchResult
{
    /**
     * @param  list<array{index: int, reason: string}>  $rejected  the events the ingest refused, by position in the batch
     */
    public function __construct(
        public int $accepted = 0,
        public int $duplicates = 0,
        public array $rejected = [],
    ) {}

    public static function fromJson(mixed $body): self
    {
        if (! is_array($body)) {
            return new self;
        }

        $rejected = [];

        foreach (is_array($body['rejected'] ?? null) ? $body['rejected'] : [] as $item) {
            if (is_array($item) && is_int($item['index'] ?? null)) {
                $rejected[] = ['index' => $item['index'], 'reason' => is_string($item['reason'] ?? null) ? $item['reason'] : 'rejected'];
            }
        }

        return new self(
            is_int($body['accepted'] ?? null) ? $body['accepted'] : 0,
            is_int($body['duplicates'] ?? null) ? $body['duplicates'] : 0,
            $rejected,
        );
    }
}
