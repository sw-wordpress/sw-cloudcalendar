<?php

declare(strict_types=1);

namespace SW\Calendar\Application\Port;

use SW\Calendar\Application\Http\HttpRequest;
use SW\Calendar\Application\Http\HttpResponse;
use SW\Calendar\Domain\CalendarException;

/**
 * Outbound HTTP with SSRF protection: scheme/port/host policy, DNS and IP checks on every redirect hop, byte and time
 * limits. Implementations never log URLs with credentials or auth headers.
 */
interface HttpClientInterface
{
    /**
     * @throws CalendarException FETCH_FAILED (network/policy), DOWNLOAD_TOO_LARGE (limit exceeded).
     */
    public function send(HttpRequest $request): HttpResponse;
}
