<?php

declare(strict_types=1);

namespace SW\Calendar\Application\Port;

use SW\Calendar\Domain\Attachment\Attachment;

interface AttachmentQueryInterface
{
    /**
     * Deliverable (stored, not revoked) attachments per occurrence.
     *
     * @param list<int> $occurrenceIds
     * @return array<int, list<Attachment>>
     */
    public function deliverableFor(array $occurrenceIds): array;

    public function find(int $id): ?Attachment;
}
