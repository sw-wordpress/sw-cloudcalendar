<?php

declare(strict_types=1);

namespace SW\Calendar\Application\Http;

final class HttpRequest
{
    /**
     * @param array<string, string> $headers
     * @param list<string>|null     $allowedHosts Fixed provider hosts (cloud APIs); null = generic external URL policy.
     */
    public function __construct(
        public readonly string $method,
        public readonly string $url,
        public readonly array $headers = [],
        public readonly ?string $body = null,
        public readonly int $timeout = 20,
        public readonly int $maxBytes = 10485760,
        /** Write the body into this server-side file instead of memory (downloads). */
        public readonly ?string $sinkFile = null,
        public readonly ?array $allowedHosts = null,
        public readonly bool $allowHttp = false,
        public readonly int $maxRedirects = 3,
    ) {
    }

    /**
     * @param array<string, string> $headers
     * @param list<string>|null     $allowedHosts
     */
    public static function get(string $url, array $headers = [], ?array $allowedHosts = null): self
    {
        return new self('GET', $url, $headers, null, 20, 10485760, null, $allowedHosts);
    }

    public function withSink(string $file, int $maxBytes): self
    {
        return new self($this->method, $this->url, $this->headers, $this->body, max($this->timeout, 60), $maxBytes, $file, $this->allowedHosts, $this->allowHttp, $this->maxRedirects);
    }

    public function withUrl(string $url): self
    {
        return new self($this->method, $url, $this->headers, $this->body, $this->timeout, $this->maxBytes, $this->sinkFile, $this->allowedHosts, $this->allowHttp, $this->maxRedirects);
    }
}
