<?php

declare(strict_types=1);

namespace SW\Calendar\Application\Port;

use SW\Calendar\Application\Attachments\StoredObject;
use SW\Calendar\Domain\CalendarException;

/**
 * Private file storage (SPECIFICATIONS.md §5, §8.4). Keys are generated internally and validated on every use.
 */
interface AttachmentStorageInterface
{
    /**
     * Atomically moves a temporary file into the storage.
     *
     * @throws CalendarException STORAGE_UNSAFE
     */
    public function putFromTemp(string $temporaryFile, string $storageKey): StoredObject;

    /**
     * @return resource
     * @throws CalendarException NOT_FOUND / STORAGE_UNSAFE
     */
    public function openReadStream(string $storageKey): mixed;

    public function delete(string $storageKey): void;

    public function newKey(string $suffix = ''): string;

    /** Server-only temporary file inside the storage (same file system: atomic rename). */
    public function temporaryFile(): string;

    public function size(string $storageKey): ?int;

    /** Absolute server path of a stored object (server-side processing only, never exposed). */
    public function localPath(string $storageKey): string;
}
