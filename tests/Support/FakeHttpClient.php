<?php

declare(strict_types=1);

namespace SW\Calendar\Tests\Support;

use SW\Calendar\Application\Http\HttpRequest;
use SW\Calendar\Application\Http\HttpResponse;
use SW\Calendar\Application\Port\HttpClientInterface;

/**
 * Returns queued responses and records every request (URL, headers) for assertions.
 */
final class FakeHttpClient implements HttpClientInterface
{
    /** @var list<HttpRequest> */
    public array $requests = [];
    /** @var list<HttpResponse|\Closure> */
    public array $queue = [];

    public function push(int $status, array|string $body, array $headers = []): void
    {
        $this->queue[] = new HttpResponse($status, array_change_key_case($headers), is_array($body) ? (string) json_encode($body) : $body, 0);
    }

    public function send(HttpRequest $request): HttpResponse
    {
        $this->requests[] = $request;
        $next = array_shift($this->queue);
        if ($next instanceof \Closure) {
            $next = $next($request);
        }
        if ($next === null) {
            throw new \LogicException('Unexpected request: ' . $request->url);
        }
        if ($request->sinkFile !== null) {
            file_put_contents($request->sinkFile, $next->body);
            return new HttpResponse($next->status, $next->headers, '', strlen($next->body));
        }
        return $next;
    }
}
