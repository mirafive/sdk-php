<?php

declare(strict_types=1);

namespace MiraFive\Tests\Support;

use MiraFive\Http\Response;
use MiraFive\Http\Transport;
use RuntimeException;
use Throwable;

final class FakeTransport implements Transport
{
    /** @var list<Response|Throwable> */
    private array $answers;

    /** @var list<array{method: string, url: string, headers: array<string, string>, body: string|null, timeoutMs: int, connectTimeoutMs: int}> */
    public array $requests = [];

    public function __construct(Response|Throwable ...$answers)
    {
        $this->answers = array_values($answers);
    }

    public function queue(Response|Throwable ...$answers): self
    {
        array_push($this->answers, ...$answers);

        return $this;
    }

    public function request(string $method, string $url, array $headers, ?string $body, int $timeoutMs, int $connectTimeoutMs): Response
    {
        $this->requests[] = compact('method', 'url', 'headers', 'body', 'timeoutMs', 'connectTimeoutMs');
        $answer = array_shift($this->answers) ?? throw new RuntimeException("No answer queued for {$method} {$url}.");

        if ($answer instanceof Throwable) {
            throw $answer;
        }

        return $answer;
    }

    /**
     * @return array<array-key, mixed>
     */
    public function body(int $index): array
    {
        $body = json_decode($this->requests[$index]['body'] ?? '', true);

        return is_array($body) ? $body : [];
    }
}
