<?php

declare(strict_types=1);

namespace SW\Calendar\Application\Render\Component;

use SW\Calendar\Application\Port\UrlGeneratorInterface;
use SW\Calendar\Application\Render\RenderLabels;
use SW\Calendar\Application\Render\Template\TemplateCompiler;
use SW\Calendar\Application\Render\Template\TrustedHtml;
use SW\Calendar\Domain\Attachment\Attachment;

/**
 * Trusted components for attachments. Only local, WordPress controlled URLs are emitted; provider URLs never appear.
 */
final class AttachmentRenderer
{
    public function __construct(
        private readonly UrlGeneratorInterface $urls,
        private readonly RenderLabels $labels,
    ) {
    }

    /**
     * @param list<Attachment> $attachments Deliverable attachments of one occurrence.
     */
    public function gallery(array $attachments): TrustedHtml
    {
        $images = array_values(array_filter($attachments, static fn (Attachment $a): bool => $a->isImage && $a->isDeliverable()));
        if ($images === []) {
            return new TrustedHtml('');
        }
        $html = '<div class="swc-gallery" role="group" aria-label="' . $this->e($this->labels->images) . '">';
        foreach ($images as $image) {
            $src = $image->previewKey !== null ? $this->urls->attachmentPreview($image->id) : $this->urls->attachmentContent($image->id);
            $html .= '<a class="swc-gallery__item" href="' . $this->e($this->urls->attachmentContent($image->id)) . '">'
                . '<img src="' . $this->e($src) . '" alt="' . $this->e(self::stripExtension($image->filename)) . '" loading="lazy" decoding="async">'
                . '</a>';
        }
        return new TrustedHtml($html . '</div>');
    }

    /**
     * @param list<Attachment> $attachments
     */
    public function fileList(array $attachments): TrustedHtml
    {
        $files = array_values(array_filter($attachments, static fn (Attachment $a): bool => !$a->isImage && $a->isDeliverable()));
        if ($files === []) {
            return new TrustedHtml('');
        }
        $html = '<ul class="swc-files" aria-label="' . $this->e($this->labels->files) . '">';
        foreach ($files as $file) {
            $html .= '<li class="swc-files__item">'
                . '<a class="swc-files__link" href="' . $this->e($this->urls->attachmentContent($file->id)) . '" download'
                . ' aria-label="' . $this->e(sprintf($this->labels->download, $file->filename)) . '">'
                . '<span class="swc-file-icon swc-file-icon--' . self::iconFor($file->mime) . '" aria-hidden="true"></span>'
                . '<span class="swc-files__name">' . $this->e($file->filename) . '</span> '
                . '<span class="swc-files__size">' . $this->e($this->bytes($file->sizeBytes)) . '</span>'
                . '</a></li>';
        }
        return new TrustedHtml($html . '</ul>');
    }

    public static function iconFor(string $mime): string
    {
        return match (true) {
            $mime === 'application/pdf' => 'pdf',
            str_starts_with($mime, 'image/') => 'image',
            str_contains($mime, 'wordprocessing') || $mime === 'application/msword' || str_contains($mime, 'opendocument.text') || $mime === 'application/rtf' => 'doc',
            str_contains($mime, 'spreadsheet') || $mime === 'application/vnd.ms-excel' || $mime === 'text/csv' => 'sheet',
            str_contains($mime, 'presentation') || $mime === 'application/vnd.ms-powerpoint' => 'slides',
            $mime === 'application/zip' => 'archive',
            str_starts_with($mime, 'text/') => 'text',
            default => 'file',
        };
    }

    public function bytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $value = (float) max(0, $bytes);
        $unit = 0;
        while ($value >= 1024 && $unit < count($units) - 1) {
            $value /= 1024;
            $unit++;
        }
        $decimals = $unit === 0 || $value >= 10 ? 0 : 1;
        return number_format($value, $decimals, $this->labels->decimalPoint, $this->labels->thousandsSeparator) . ' ' . $units[$unit];
    }

    private static function stripExtension(string $filename): string
    {
        $dot = strrpos($filename, '.');
        return $dot === false || $dot === 0 ? $filename : substr($filename, 0, $dot);
    }

    private function e(string $value): string
    {
        return TemplateCompiler::escape($value);
    }
}
