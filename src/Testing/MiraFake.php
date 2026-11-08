<?php

declare(strict_types=1);

namespace MiraFive\Testing;

use MiraFive\Mira;
use MiraFive\MiraError;
use PHPUnit\Framework\Assert;

/**
 * A real client on a RecordingTransport, with assertions over what your code sent. Input is checked
 * exactly as in production, so an event the ingest would refuse fails the test the same way: it
 * never arrives, and `assertNoErrors()` names the problem.
 *
 * Each assertion flushes first, so events still waiting count. Callbacks receive the wire event as
 * an array with the wire's snake_case keys: `event`, `user_id`, `properties`, `revenue`, `traits`, …
 *
 * ```php
 * $fake = MiraFake::create();
 * (new Checkout($fake->mira))->complete($order);
 * $fake->assertRevenue(fn (array $event) => $event['revenue']['amount'] === 4900);
 * ```
 *
 * @phpstan-type WireEvent array<string, mixed>
 */
final readonly class MiraFake
{
    /** The key of a fake client; it never leaves the process. */
    public const string KEY = 'mira_sk_fake';

    public function __construct(public Mira $mira, public RecordingTransport $transport) {}

    public static function create(?string $host = null): self
    {
        $transport = new RecordingTransport;
        $mira = new Mira(
            secretKey: self::KEY,
            host: $host ?? Mira::DEFAULT_HOST,
            onError: $transport->recordError(...),
            transport: $transport,
            flushOnShutdown: false,
            sleep: static function (): void {},
        );

        return new self($mira, $transport);
    }

    /**
     * The events delivered so far, optionally only those with this name (`$revenue`, `$identify`, …).
     *
     * @return list<WireEvent>
     */
    public function events(?string $event = null): array
    {
        $this->mira->flush();
        $events = [];

        foreach ($this->transport->batches() as $batch) {
            foreach (is_array($batch['events'] ?? null) ? $batch['events'] : [] as $item) {
                if (is_array($item) && ($event === null || ($item['event'] ?? null) === $event)) {
                    $id = is_string($item['event_id'] ?? null) ? $item['event_id'] : count($events);
                    /** @var WireEvent $item */
                    $events[$id] = $item;
                }
            }
        }

        return array_values($events);
    }

    /**
     * @return list<MiraError>
     */
    public function errors(): array
    {
        $this->mira->flush();

        return $this->transport->errors();
    }

    /**
     * @param  (callable(WireEvent): bool)|int|null  $where  a filter, or how many times exactly
     */
    public function assertTracked(string $event, callable|int|null $where = null): void
    {
        $this->assertSent($event, $where, "The event \"{$event}\"");
    }

    /**
     * @param  (callable(WireEvent): bool)|null  $where
     */
    public function assertNotTracked(string $event, ?callable $where = null): void
    {
        Assert::assertEmpty($this->matching($event, $where), "The event \"{$event}\" was tracked.");
    }

    /**
     * @param  (callable(WireEvent): bool)|null  $where
     */
    public function assertIdentified(?string $userId = null, ?callable $where = null): void
    {
        $matches = array_filter(
            $this->matching('$identify', $where),
            static fn (array $event): bool => $userId === null || ($event['user_id'] ?? null) === $userId,
        );

        Assert::assertNotEmpty($matches, $userId === null ? 'No user was identified.' : "The user \"{$userId}\" was not identified.");
    }

    /**
     * @param  (callable(WireEvent): bool)|null  $where
     */
    public function assertGrouped(?string $groupId = null, ?callable $where = null): void
    {
        $matches = array_filter(
            $this->matching('$group', $where),
            static fn (array $event): bool => $groupId === null || ($event['group_id'] ?? null) === $groupId,
        );

        Assert::assertNotEmpty($matches, $groupId === null ? 'No group was sent.' : "The group \"{$groupId}\" was not sent.");
    }

    /**
     * @param  (callable(WireEvent): bool)|int|null  $where  a filter, or how many times exactly
     */
    public function assertRevenue(callable|int|null $where = null): void
    {
        $this->assertSent('$revenue', $where, 'Revenue');
    }

    /**
     * @param  (callable(WireEvent): bool)|int|null  $where  a filter, or how many times exactly
     */
    public function assertTouchpoint(callable|int|null $where = null): void
    {
        $this->assertSent('$touchpoint', $where, 'A touchpoint');
    }

    public function assertNothingTracked(): void
    {
        $names = array_map(static fn (array $event): string => is_string($event['event'] ?? null) ? $event['event'] : '?', $this->events());

        Assert::assertEmpty($names, 'Events were sent: '.implode(', ', $names).'.');
    }

    /** No event was refused by the client or the ingest, and every batch was delivered. */
    public function assertNoErrors(): void
    {
        $messages = array_map(static fn (MiraError $error): string => $error->getMessage(), $this->errors());

        Assert::assertEmpty($messages, "MIRA FIVE reported problems:\n- ".implode("\n- ", $messages));
    }

    /** Forgets everything recorded so far. */
    public function clear(): void
    {
        $this->mira->flush();
        $this->transport->clear();
    }

    /**
     * @param  (callable(WireEvent): bool)|int|null  $where
     */
    private function assertSent(string $event, callable|int|null $where, string $label): void
    {
        if (is_int($where)) {
            $count = count($this->events($event));
            Assert::assertSame($where, $count, "{$label} was sent {$count} times, not {$where}.");

            return;
        }

        Assert::assertNotEmpty($this->matching($event, $where), "{$label} was not sent".($where === null ? '.' : ' with the expected details.'));
    }

    /**
     * @param  (callable(WireEvent): bool)|null  $where
     * @return list<WireEvent>
     */
    private function matching(string $event, ?callable $where): array
    {
        return array_values(array_filter($this->events($event), static fn (array $item): bool => $where === null || $where($item)));
    }
}
