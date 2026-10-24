<?php

declare(strict_types=1);

namespace SW\Calendar\Application\Http;

final class HttpResponse
{
    /**
     * @param array<string, string> $headers Lower-case header names.
     */
    public function __construct(
        public readonly int $status,
        public readonly array $headers,
        public readonly string $body,
        public readonly int $bytes = 0,
    ) {
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function json(): ?array
    {
        $data = json_decode($this->body, true);
        return is_array($data) ? $data : null;
    }

    public function retryAfterSeconds(): ?int
    {
        $value = $this->header('retry-after');
        if ($value === null) {
            return null;
        }
        if (ctype_digit(trim($value))) {
            return (int) trim($value);
        }
        $time = strtotime($value);
        return $time === false ? null : max(0, $time - time());
    }
}
