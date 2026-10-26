<?php

declare(strict_types=1);

namespace SW\Calendar\Application\Port;

interface UrlGeneratorInterface
{
    public function attachmentContent(int $attachmentId): string;

    public function attachmentPreview(int $attachmentId): string;

    public function eventDetail(int $viewId, int $occurrenceId): string;

    public function month(int $viewId, string $month): string;

    /** Earlier (negative) or further (positive) result page of a list. */
    public function page(int $viewId, int $page): string;
}
