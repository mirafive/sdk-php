<?php

declare(strict_types=1);

namespace MiraFive\Http;

final readonly class Response
{
    /** @var array<string, string> */
    public array $headers;

    /**
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public int $status,
        array $headers = [],
        public string $body = '',
    ) {
        $this->headers = array_change_key_case($headers, CASE_LOWER);
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function json(): mixed
    {
        return $this->body === '' ? null : json_decode($this->body, true);
    }
}
