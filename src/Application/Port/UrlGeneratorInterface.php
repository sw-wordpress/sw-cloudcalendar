<?php

declare(strict_types=1);

namespace SW\Calendar\Application\Port;

interface UrlGeneratorInterface
{
    public function attachmentContent(int $attachmentId): string;

    public function attachmentPreview(int $attachmentId): string;

    public function eventDetail(int $viewId, int|string $occurrenceId): string;

    /** @param array<string, mixed> $filters Mode, search, month and page for progressive-enhancement links. */
    public function calendar(int $viewId, array $filters): string;

    public function month(int $viewId, string $month): string;

    /** Earlier (negative) or further (positive) result page of a list. */
    public function page(int $viewId, int $page, ?string $month = null): string;
}
