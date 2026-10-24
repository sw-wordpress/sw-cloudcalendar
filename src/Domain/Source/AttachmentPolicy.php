<?php

declare(strict_types=1);

namespace SW\Calendar\Domain\Source;

use SW\Calendar\Domain\Attachment\PublicationState;

/**
 * Attachment settings of a source (SPECIFICATIONS.md §8.1). `public_only` limits the SET of synchronised files; the
 * WordPress visibility of synchronised files always follows the source ACL.
 */
final class AttachmentPolicy
{
    public const DEFAULT_MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/gif',
        'application/pdf',
        'text/plain',
        'application/zip',
    ];

    /**
     * MIME types that may ever be allowed. Active content (HTML, SVG, scripts, executables) is never accepted.
     */
    public const SUPPORTED_MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/gif',
        'application/pdf',
        'text/plain',
        'text/csv',
        'application/zip',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.ms-powerpoint',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'application/vnd.oasis.opendocument.text',
        'application/vnd.oasis.opendocument.spreadsheet',
        'application/vnd.oasis.opendocument.presentation',
        'application/rtf',
    ];

    public const MAX_FILE_SIZE_MB = 200;

    /**
     * @param list<string> $allowedMimeTypes
     */
    public function __construct(
        public readonly bool $syncAttachments = false,
        public readonly bool $publicOnly = true,
        public readonly int $maxFileSizeMb = 20,
        public readonly array $allowedMimeTypes = self::DEFAULT_MIME_TYPES,
        public readonly bool $generateImagePreviews = true,
        public readonly bool $showImages = true,
        public readonly bool $showFileList = true,
        public readonly int $quotaMb = 500,
        public readonly int $refreshHours = 24,
        public readonly ?Confirmation $privateAttachmentsConfirmation = null,
    ) {
    }

    /**
     * Builds a policy from stored or submitted data. Invalid values are reported, never silently clamped.
     *
     * @param array<string, mixed> $data
     * @param list<string>         $errors Receives field => message style error keys.
     */
    public static function fromArray(array $data, array &$errors = []): self
    {
        $default = new self();
        $maxMb = self::intIn($data, 'max_file_size_mb', $default->maxFileSizeMb, 1, self::MAX_FILE_SIZE_MB, $errors);
        $quota = self::intIn($data, 'quota_mb', $default->quotaMb, 1, 100000, $errors);
        $refresh = self::intIn($data, 'refresh_hours', $default->refreshHours, 1, 24 * 30, $errors);
        $mimes = $default->allowedMimeTypes;
        if (array_key_exists('allowed_mime_types', $data)) {
            $mimes = [];
            if (!is_array($data['allowed_mime_types'])) {
                $errors[] = 'allowed_mime_types';
            } else {
                foreach ($data['allowed_mime_types'] as $mime) {
                    $mime = strtolower(trim((string) $mime));
                    if (!in_array($mime, self::SUPPORTED_MIME_TYPES, true)) {
                        $errors[] = 'allowed_mime_types';
                        continue;
                    }
                    $mimes[] = $mime;
                }
                $mimes = array_values(array_unique($mimes));
            }
        }
        $confirmation = Confirmation::fromArray($data['publish_private_attachments_confirmation'] ?? null);

        return new self(
            (bool) ($data['sync_attachments'] ?? $default->syncAttachments),
            (bool) ($data['public_only'] ?? $default->publicOnly),
            $maxMb,
            $mimes,
            (bool) ($data['generate_image_previews'] ?? $default->generateImagePreviews),
            (bool) ($data['show_images'] ?? $default->showImages),
            (bool) ($data['show_file_list'] ?? $default->showFileList),
            $quota,
            $refresh,
            $confirmation,
        );
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string>         $errors
     */
    private static function intIn(array $data, string $key, int $default, int $min, int $max, array &$errors): int
    {
        if (!array_key_exists($key, $data)) {
            return $default;
        }
        $value = filter_var($data[$key], FILTER_VALIDATE_INT);
        if ($value === false || $value < $min || $value > $max) {
            $errors[] = $key;
            return $default;
        }
        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'sync_attachments' => $this->syncAttachments,
            'public_only' => $this->publicOnly,
            'max_file_size_mb' => $this->maxFileSizeMb,
            'allowed_mime_types' => $this->allowedMimeTypes,
            'generate_image_previews' => $this->generateImagePreviews,
            'show_images' => $this->showImages,
            'show_file_list' => $this->showFileList,
            'quota_mb' => $this->quotaMb,
            'refresh_hours' => $this->refreshHours,
            'publish_private_attachments_confirmed' => $this->privateAttachmentsConfirmation !== null,
            'publish_private_attachments_confirmation' => $this->privateAttachmentsConfirmation?->toArray(),
        ];
    }

    public function maxBytes(): int
    {
        return $this->maxFileSizeMb * 1024 * 1024;
    }

    public function allowsMime(string $mime): bool
    {
        return in_array(strtolower($mime), $this->allowedMimeTypes, true);
    }

    /**
     * Whether a file with the given publication state may be synchronised for a source with the given visibility.
     *
     * - `public_only`: only verified public files (unknown = not public, fail closed).
     * - otherwise readable private files are allowed, but on a WordPress-public source only after the separate,
     *   explicit confirmation to publish private files.
     */
    public function permits(PublicationState $state, Visibility $visibility): bool
    {
        if (!$this->syncAttachments) {
            return false;
        }
        if ($state === PublicationState::PublicVerified) {
            return true;
        }
        if ($this->publicOnly) {
            return false;
        }
        if ($state !== PublicationState::Private && $state !== PublicationState::Unknown) {
            return false;
        }
        if ($visibility === Visibility::Public) {
            return $this->privateAttachmentsConfirmation !== null;
        }
        return true;
    }

    /**
     * Whether a configuration change requires the explicit private-attachment confirmation before it can be saved.
     */
    public function requiresPrivateConfirmation(Visibility $visibility): bool
    {
        return $this->syncAttachments && !$this->publicOnly && $visibility === Visibility::Public;
    }
}
