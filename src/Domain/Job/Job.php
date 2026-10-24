<?php

declare(strict_types=1);

namespace SW\Calendar\Domain\Job;

final class Job
{
    /**
     * @param array<string, scalar|null> $payload
     */
    public function __construct(
        public readonly int $id,
        public readonly ?int $sourceId,
        public readonly JobType $type,
        public readonly array $payload,
        public readonly JobStatus $status,
        public readonly int $attempts,
        public readonly \DateTimeImmutable $runAfter,
        public readonly string $idempotencyKey,
        public readonly ?string $lastError = null,
    ) {
    }
}
