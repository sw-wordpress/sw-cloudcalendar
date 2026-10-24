<?php

declare(strict_types=1);

namespace SW\Calendar\Application\Sync;

/**
 * Per run metrics (SPECIFICATIONS.md §7.3). Contains counts and codes only: no tokens, URLs or event contents.
 */
final class SyncMetrics
{
    public string $provider = '';
    public string $mode = 'full';
    public int $pages = 0;
    public int $eventsRead = 0;
    public int $removedRead = 0;
    public int $created = 0;
    public int $updated = 0;
    public int $deleted = 0;
    public int $attachmentsPlanned = 0;
    public int $attachmentsRevoked = 0;
    public int $durationMs = 0;
    public bool $notModified = false;
    /** @var list<string> */
    public array $warnings = [];

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
