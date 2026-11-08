<?php

declare(strict_types=1);

namespace MiraFive\Internal;

use JsonException;
use MiraFive\MiraError;
use stdClass;

/**
 * @internal One batch as it is handed off and delivered: `{stream, seq, events}`, encoded once.
 * `sent_at` is put in front at each attempt, because the ingest corrects event times by the gap
 * between `sent_at` and arrival; a batch that waited in a queue must not look skewed.
 */
final readonly class Batch
{
    /** The ingest refuses bodies over 512 KiB; batches stay below this, with room for `sent_at`. */
    public const int MAX_BYTES = 500_000;

    /**
     * @param  list<string>  $eventIds
     */
    private function __construct(public string $payload, public array $eventIds) {}

    /**
     * @param  list<string>  $events  each one wire event, encoded
     * @param  list<string>  $eventIds
     */
    public static function make(string $stream, int $seq, array $events, array $eventIds): self
    {
        $payload = '{"stream":'.Json::encode($stream).',"seq":'.$seq.',"events":['.implode(',', $events).']}';

        return new self($payload, $eventIds);
    }

    /**
     * A payload from a hand-off, checked to be one.
     *
     * @throws MiraError
     */
    public static function parse(string $payload): self
    {
        try {
            $batch = json_decode($payload, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $batch = null;
        }

        if (! $batch instanceof stdClass || ! is_string($batch->stream ?? null) || ! is_int($batch->seq ?? null) || ! is_array($batch->events ?? null) || $batch->events === [] || property_exists($batch, 'sent_at')) {
            throw new MiraError('Not a prepared MIRA FIVE batch: expected {"stream", "seq", "events"} as the SDK hands it off');
        }

        $ids = [];

        foreach ($batch->events as $event) {
            $ids[] = $event instanceof stdClass && is_string($event->event_id ?? null) ? $event->event_id : '';
        }

        return new self($payload, $ids);
    }

    public function body(string $sentAt): string
    {
        return '{"sent_at":'.Json::encode($sentAt).','.substr($this->payload, 1);
    }
}
