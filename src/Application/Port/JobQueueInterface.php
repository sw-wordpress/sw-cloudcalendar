<?php

declare(strict_types=1);

namespace SW\Calendar\Application\Port;

use SW\Calendar\Domain\Job\Job;
use SW\Calendar\Domain\Job\JobType;

interface JobQueueInterface
{
    /**
     * Idempotent: a queued or running job with the same key is not duplicated.
     *
     * @param array<string, scalar|null> $payload
     */
    public function enqueue(JobType $type, ?int $sourceId, array $payload, string $idempotencyKey, \DateTimeImmutable $runAfter): void;

    /**
     * Atomically claims the next due job (lease until `$leaseUntil`).
     */
    public function claim(\DateTimeImmutable $now, \DateTimeImmutable $leaseUntil): ?Job;

    public function complete(int $jobId): void;

    public function fail(int $jobId, string $error, ?\DateTimeImmutable $retryAt): void;

    public function deleteSource(int $sourceId): void;
}
