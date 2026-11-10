<?php

declare(strict_types=1);

namespace MiraFive\Testing;

use JsonException;
use MiraFive\Http\Response;
use MiraFive\Http\Transport;
use MiraFive\MiraError;
use Throwable;

/**
 * A transport that never touches the network: it records every batch and answers like the ingest
 * (202 with every event accepted), unless you queue another answer. It also collects the problems
 * the client reports, when you pass `onError: $transport->recordError(...)`.
 */
final class RecordingTransport implements Transport
{
    /** @var list<array{url: string, headers: array<string, string>, body: string, batch: array<array-key, mixed>, status: int|null}> */
    private array $requests = [];

    /** @var list<Response|Throwable> */
    private array $answers = [];

    /** @var list<MiraError> */
    private array $errors = [];

    public function request(string $method, string $url, array $headers, ?string $body, int $timeoutMs, int $connectTimeoutMs): Response
    {
        try {
            $batch = json_decode($body ?? '', true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $batch = null;
        }

        $answer = array_shift($this->answers);
        $events = is_array($batch) && is_array($batch['events'] ?? null) ? count($batch['events']) : 0;
        // An erasure is answered with its proof: zero rows, like a person nothing is stored about.
        $erasure = $method === 'DELETE' && str_contains($url, '/v1/persons/');
        $answer ??= $erasure
            ? new Response(200, ['content-type' => 'application/json'], (string) json_encode(['request_id' => '00000000-0000-7000-8000-000000000000', 'deleted' => ['events' => 0], 'revenue_kept' => 0, 'completed_at' => gmdate('Y-m-d\TH:i:s.000\Z')]))
            : null;
        $answer ??= new Response(202, ['content-type' => 'application/json'], (string) json_encode(['accepted' => $events, 'duplicates' => 0, 'rejected' => []]));
        $this->requests[] = [
            'method' => $method,
            'url' => $url,
            'headers' => $headers,
            'body' => $body ?? '',
            'batch' => is_array($batch) ? $batch : [],
            'status' => $answer instanceof Response ? $answer->status : null,
        ];

        if ($answer instanceof Throwable) {
            throw $answer;
        }

        return $answer;
    }

    /** Answers the next request with this instead of a 202, e.g. `new Response(503)` or a TransportException. */
    public function answerNext(Response|Throwable ...$answers): self
    {
        array_push($this->answers, ...$answers);

        return $this;
    }

    public function recordError(MiraError $error): void
    {
        $this->errors[] = $error;
    }

    /**
     * Every request, with its decoded batch and the status it was answered with (null: a thrown error).
     *
     * @return list<array{url: string, headers: array<string, string>, body: string, batch: array<array-key, mixed>, status: int|null}>
     */
    public function requests(): array
    {
        return $this->requests;
    }

    /**
     * The batches that were answered with 2xx, decoded. A retried batch appears once per success.
     *
     * @return list<array<array-key, mixed>>
     */
    public function batches(): array
    {
        $batches = [];

        foreach ($this->requests as $request) {
            if ($request['status'] !== null && $request['status'] >= 200 && $request['status'] < 300) {
                $batches[] = $request['batch'];
            }
        }

        return $batches;
    }

    /**
     * @return list<MiraError>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    public function clear(): void
    {
        $this->requests = [];
        $this->answers = [];
        $this->errors = [];
    }
}
