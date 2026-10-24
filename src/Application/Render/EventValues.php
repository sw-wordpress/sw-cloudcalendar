<?php

declare(strict_types=1);

namespace SW\Calendar\Application\Render;

use SW\Calendar\Application\Port\DateFormatterInterface;
use SW\Calendar\Application\Port\HtmlSanitizerInterface;
use SW\Calendar\Application\Port\UrlGeneratorInterface;
use SW\Calendar\Application\Render\Component\AttachmentRenderer;
use SW\Calendar\Application\Render\Template\TrustedHtml;
use SW\Calendar\Domain\Attachment\Attachment;
use SW\Calendar\Domain\Event\Occurrence;
use SW\Calendar\Domain\Event\OccurrenceStatus;
use SW\Calendar\Domain\Source\Source;

/**
 * Builds the placeholder value map of one occurrence.
 */
final class EventValues
{
    public function __construct(
        private readonly DateFormatterInterface $dates,
        private readonly UrlGeneratorInterface $urls,
        private readonly HtmlSanitizerInterface $sanitizer,
        private readonly AttachmentRenderer $attachments,
    ) {
    }

    /**
     * @param list<Attachment> $attachments Deliverable attachments (already filtered by source ACL).
     * @return array<string, string|bool|TrustedHtml|null>
     */
    public function build(
        Occurrence $occurrence,
        ?Source $source,
        array $attachments,
        int $viewId,
        \DateTimeZone $tz,
        string $dateFormat,
        string $timeFormat,
        bool $showImages,
        bool $showFiles,
        ?string $colorOverride = null,
    ): array {
        [$start, $end, $endInclusive] = self::localTimes($occurrence, $tz);
        $multiDay = $start->format('Y-m-d') !== $endInclusive->format('Y-m-d');
        $startDate = $this->dates->format($start, $tz, $dateFormat);
        $endDate = $this->dates->format($endInclusive, $tz, $dateFormat);
        $startTime = $occurrence->allDay ? '' : $this->dates->format($start, $tz, $timeFormat);
        $endTime = $occurrence->allDay ? '' : $this->dates->format($end, $tz, $timeFormat);

        if ($occurrence->allDay) {
            $range = $multiDay ? $startDate . ' – ' . $endDate : $startDate;
        } elseif ($multiDay) {
            $range = $startDate . ' ' . $startTime . ' – ' . $endDate . ' ' . $endTime;
        } else {
            $range = $startDate . ', ' . $startTime . ($endTime !== '' && $end > $start ? ' – ' . $endTime : '');
        }

        $showImages = $showImages && ($source === null || $source->settings->attachments->showImages);
        $showFiles = $showFiles && ($source === null || $source->settings->attachments->showFileList);
        $images = $showImages ? array_values(array_filter($attachments, static fn (Attachment $a): bool => $a->isImage)) : [];
        $files = $showFiles ? array_values(array_filter($attachments, static fn (Attachment $a): bool => !$a->isImage)) : [];
        $firstImage = $images[0] ?? null;
        $descriptionHtml = $occurrence->descriptionHtml !== '' ? $this->sanitizer->sanitizeDescription($occurrence->descriptionHtml) : '';

        return [
            'event.id' => (string) $occurrence->id,
            'event.title' => $occurrence->title,
            'event.description' => $occurrence->descriptionText,
            'event.description_html' => new TrustedHtml($descriptionHtml),
            'event.excerpt' => self::excerpt($occurrence->descriptionText, 160),
            'event.location' => $occurrence->location,
            'event.url' => $occurrence->externalUrl,
            'event.detail_url' => $this->urls->eventDetail($viewId, $occurrence->id),
            'event.start_date' => $startDate,
            'event.start_time' => $startTime,
            'event.end_date' => $endDate,
            'event.end_time' => $endTime,
            'event.date_range' => $range,
            'event.start_iso' => $occurrence->allDay ? $start->format('Y-m-d') : $start->format(DATE_ATOM),
            'event.end_iso' => $occurrence->allDay ? $endInclusive->format('Y-m-d') : $end->format(DATE_ATOM),
            'event.day' => $this->dates->format($start, $tz, 'j'),
            'event.month_short' => $this->dates->format($start, $tz, 'M'),
            'event.weekday' => $this->dates->format($start, $tz, 'l'),
            'event.all_day' => $occurrence->allDay,
            'event.multi_day' => $multiDay,
            'event.tentative' => $occurrence->status === OccurrenceStatus::Tentative,
            'event.calendar_name' => $source->name ?? '',
            'event.color' => $colorOverride ?? $source->color ?? '',
            'event.image' => $firstImage === null ? '' : ($firstImage->previewKey !== null ? $this->urls->attachmentPreview($firstImage->id) : $this->urls->attachmentContent($firstImage->id)),
            'event.has_images' => $images !== [],
            'event.has_files' => $files !== [],
            'event.attachments.gallery' => $this->attachments->gallery($images),
            'event.attachments.file_list' => $this->attachments->fileList($files),
        ];
    }

    /**
     * Local start, local exclusive end and local inclusive end of an occurrence.
     *
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable, 2: \DateTimeImmutable}
     */
    public static function localTimes(Occurrence $occurrence, \DateTimeZone $tz): array
    {
        if ($occurrence->allDay) {
            // Floating dates: the calendar date is the same in every time zone.
            $start = new \DateTimeImmutable($occurrence->startUtc->format('Y-m-d') . ' 00:00:00', $tz);
            $end = new \DateTimeImmutable($occurrence->endUtc->format('Y-m-d') . ' 00:00:00', $tz);
            if ($end <= $start) {
                $end = $start->modify('+1 day');
            }
            return [$start, $end, $end->modify('-1 day')];
        }
        $start = $occurrence->startUtc->setTimezone($tz);
        $end = $occurrence->endUtc->setTimezone($tz);
        $inclusive = $end > $start ? $end->modify('-1 second') : $start;
        return [$start, $end, $inclusive];
    }

    public static function excerpt(string $text, int $length): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        if (mb_strlen($text) <= $length) {
            return $text;
        }
        $cut = mb_substr($text, 0, $length);
        $space = mb_strrpos($cut, ' ');
        return rtrim($space !== false && $space > $length / 2 ? mb_substr($cut, 0, $space) : $cut, " ,.;:") . '…';
    }
}
