<?php

declare(strict_types=1);

namespace MiraFive\Internal;

use Closure;
use MiraFive\BatchResult;
use MiraFive\MiraError;
use MiraFive\PersonErasure;
use Throwable;
use WeakReference;

/**
 * @internal The buffer behind a client and every scope made with `with()`. Events wait here until
 * `flushAt` are waiting, `flush()` is called, or the request ends; then they leave in batches of at
 * most 100 events and 500 kB, one batch at a time so their order is kept.
 *
 * Batch sequence numbers (docs/integrity.md, Reconciliation): a random `stream` per client, held in
 * memory only, and a `seq` from 1, taken when a batch is formed. A retry keeps its number, and a
 * batch dropped for good leaves a gap that the ingest counts as lost.
 */
final class Pipeline
{
    /** @var list<array{json: string, id: string}> */
    private array $buffer = [];

    private int $seq = 0;

    private bool $closed = false;

    private bool $shutdownRegistered = false;

    /**
     * @param  Delivery|null  $delivery  null: events are checked, then dropped (disabled, or no key)
     * @param  (Closure(string): void)|null  $handOff  receives each batch payload instead of sending it
     */
    public function __construct(
        private readonly ?Delivery $delivery,
        private readonly Reporter $reporter,
        private readonly Breaker $breaker,
        private readonly string $stream,
        private readonly int $flushAt,
        private readonly int $flushDeadlineMs,
        private readonly bool $flushOnShutdown,
        private readonly ?Closure $handOff,
    ) {}

    public function __destruct()
    {
        $this->flush();
    }

    public function closed(): bool
    {
        return $this->closed;
    }

    /**
     * @param  array<string, mixed>  $event
     *
     * @throws MiraError
     */
    public function push(array $event, string $eventId): void
    {
        if ($this->delivery === null && $this->handOff === null) {
            return;
        }

        $this->buffer[] = ['json' => Json::encode($event), 'id' => $eventId];
        $this->registerShutdown();

        if (count($this->buffer) >= $this->flushAt) {
            $this->flush();
        }
    }

    /** Sends what is waiting, within the flush deadline. Never throws; problems are reported. */
    public function flush(): void
    {
        if ($this->buffer === []) {
            return;
        }

        $batches = $this->batches();

        if ($this->handOff !== null) {
            foreach ($batches as $batch) {
                $this->handOver($this->handOff, $batch);
            }

            return;
        }

        if ($this->breaker->isOpen()) {
            if ($this->breaker->firstSkip()) {
                $this->reporter->report(new MiraError(
                    'delivery failed recently; events are dropped without trying the network for up to 30 s',
                    eventIds: array_merge(...array_map(static fn (Batch $batch): array => $batch->eventIds, $batches)),
                    retryable: true,
                ));
            }

            return;
        }

        $budget = new Budget($this->flushDeadlineMs);

        foreach ($batches as $batch) {
            $this->deliver($batch, $budget);
        }
    }

    public function shutdown(): void
    {
        $this->flush();
        $this->closed = true;
    }

    /**
     * @return 'public'|'secret'
     *
     * @throws MiraError
     */
    public function check(): string
    {
        if ($this->delivery === null) {
            throw new MiraError('the client cannot send: it is disabled or has no secret key (MIRAFIVE_SECRET_KEY)');
        }

        return $this->delivery->check();
    }

    /**
     * @throws MiraError
     */
    public function erase(string $userId): PersonErasure
    {
        if ($this->delivery === null) {
            throw new MiraError('deletePerson needs the project\'s secret key (mira_sk_…): the client is disabled or has none (MIRAFIVE_SECRET_KEY)', reason: 'config');
        }

        return $this->delivery->erase($userId);
    }

    /**
     * Delivers one batch and reports what the ingest rejected. Used by flush() and by queue workers.
     *
     * @throws MiraError
     */
    public function send(Batch $batch, ?Budget $budget = null, bool $report = true): BatchResult
    {
        if ($this->delivery === null) {
            throw new MiraError('the client cannot send: it is disabled or has no secret key', eventIds: $batch->eventIds);
        }

        $result = $this->delivery->send($batch, $budget);

        if ($report && $result->rejected !== []) {
            $first = $result->rejected[0]['reason'];
            $count = count($result->rejected);
            $ids = array_values(array_map(static fn (array $item): string => $batch->eventIds[$item['index']] ?? '', $result->rejected));
            $this->reporter->report(new MiraError("{$count} event(s) rejected: {$first}", 202, $ids));
        }

        return $result;
    }

    private function deliver(Batch $batch, Budget $budget): void
    {
        try {
            $this->send($batch, $budget);
        } catch (MiraError $error) {
            if ($error->retryable) {
                $this->breaker->trip();
            }

            $this->reporter->report($error);
        }
    }

    /**
     * The waiting events as batches, each with its sequence number.
     *
     * @return list<Batch>
     */
    private function batches(): array
    {
        $events = $this->buffer;
        $this->buffer = [];
        $batches = [];
        $json = [];
        $ids = [];
        $bytes = 0;

        foreach ($events as $event) {
            $size = strlen($event['json']) + 1;

            if ($json !== [] && (count($json) >= $this->flushAt || $bytes + $size > Batch::MAX_BYTES)) {
                $batches[] = Batch::make($this->stream, ++$this->seq, $json, $ids);
                $json = [];
                $ids = [];
                $bytes = 0;
            }

            $json[] = $event['json'];
            $ids[] = $event['id'];
            $bytes += $size;
        }

        $batches[] = Batch::make($this->stream, ++$this->seq, $json, $ids);

        return $batches;
    }

    /**
     * @param  Closure(string): void  $handOff
     */
    private function handOver(Closure $handOff, Batch $batch): void
    {
        try {
            $handOff($batch->payload);
        } catch (Throwable $exception) {
            $this->reporter->report(new MiraError('the hand-off failed: '.$exception->getMessage(), eventIds: $batch->eventIds, previous: $exception));
        }
    }

    private function registerShutdown(): void
    {
        if (! $this->flushOnShutdown || $this->shutdownRegistered) {
            return;
        }

        $this->shutdownRegistered = true;
        // Weak, so a client dropped mid-request is flushed by its destructor and not kept alive.
        $pipeline = WeakReference::create($this);
        register_shutdown_function(static function () use ($pipeline): void {
            $pipeline->get()?->flush();
        });
    }
}
