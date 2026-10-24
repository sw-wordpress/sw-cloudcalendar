<?php

declare(strict_types=1);

namespace SW\Calendar\Application\Attachments;

use SW\Calendar\Application\Port\AttachmentStorageInterface;
use SW\Calendar\Application\Port\AttachmentStoreInterface;
use SW\Calendar\Application\Port\CacheInvalidatorInterface;
use SW\Calendar\Application\Port\ConnectionRepositoryInterface;
use SW\Calendar\Application\Port\ImagePreviewInterface;
use SW\Calendar\Application\Port\MimeDetectorInterface;
use SW\Calendar\Application\Port\ProviderRegistryInterface;
use SW\Calendar\Application\Port\SourceRepositoryInterface;
use SW\Calendar\Application\Port\StorageGuardInterface;
use SW\Calendar\Domain\Attachment\PublicationState;
use SW\Calendar\Domain\Attachment\SyncState;
use SW\Calendar\Domain\CalendarException;
use SW\Calendar\Domain\ErrorCode;
use SW\Calendar\Domain\Provider\DownloadTarget;
use SW\Calendar\Domain\Provider\ProviderAttachmentDefinition;
use SW\Calendar\Domain\Provider\ProviderAttachmentReference;
use SW\Calendar\Support\ClockInterface;
use SW\Calendar\Support\LoggerInterface;
use SW\Calendar\Support\Redactor;

/**
 * Attachment pipeline for one provider reference (SPECIFICATIONS.md §5, §8.3):
 * publication check -> metadata limits -> bounded download into a server-only temp file -> content based MIME
 * check -> SHA-256 -> atomic move into private storage -> optional preview -> record.
 *
 * Fail closed: an unknown or revoked publication status blocks delivery even of a previously stored file.
 */
final class AttachmentSyncService
{
    public function __construct(
        private readonly SourceRepositoryInterface $sources,
        private readonly ConnectionRepositoryInterface $connections,
        private readonly ProviderRegistryInterface $providers,
        private readonly AttachmentStoreInterface $attachments,
        private readonly AttachmentStorageInterface $storage,
        private readonly StorageGuardInterface $guard,
        private readonly MimeDetectorInterface $mime,
        private readonly ImagePreviewInterface $previews,
        private readonly CacheInvalidatorInterface $invalidator,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
        private readonly int $globalQuotaBytes = 2147483648,
    ) {
    }

    /**
     * @return string Outcome: stored, unchanged, skipped, failed.
     * @throws CalendarException For transient provider errors (the job is retried with backoff).
     */
    public function process(int $sourceId, string $refHash): string
    {
        $now = $this->clock->now();
        $source = $this->sources->find($sourceId);
        $policy = $source?->settings->attachments;
        if ($source === null || $policy === null || !$source->isReadable() || !$policy->syncAttachments) {
            return 'skipped';
        }
        $definitionData = $this->attachments->definition($sourceId, $refHash);
        if ($definitionData === null) {
            return 'skipped';
        }
        $definition = new ProviderAttachmentDefinition(
            $definitionData['kind'],
            $definitionData['reference'],
            $definitionData['filename'],
            $definitionData['mime'],
            $definitionData['size'] > 0 ? $definitionData['size'] : null,
            $definitionData['version'],
        );
        if (!$this->guard->isSafe()) {
            $this->attachments->markFailed($sourceId, $refHash, ErrorCode::StorageUnsafe->value . ': ' . $this->guard->reason(), false, $now);
            return 'failed';
        }
        $connection = $this->connections->find($source->connectionId);
        if ($connection === null || !$connection->status->isUsable()) {
            $this->attachments->markFailed($sourceId, $refHash, ErrorCode::AuthRequired->value, false, $now);
            return 'failed';
        }
        $provider = $this->providers->get($connection->provider);
        $reference = new ProviderAttachmentReference($definition, $definitionData['provider_event_id']);

        try {
            $inspection = $provider->inspectAttachment($connection, $source, $reference);
        } catch (CalendarException $e) {
            // Unknown publication status: fail closed for delivery, retry later for transient errors.
            $this->attachments->markFailed($sourceId, $refHash, $e->errorCode->value, false, $now);
            $this->invalidator->sourceChanged($sourceId);
            if ($e->isRetryable()) {
                throw $e;
            }
            return 'failed';
        }

        $state = $inspection->publicationState;
        if ($state === PublicationState::Unsupported || $state === PublicationState::NotApplicable) {
            return $this->skip($sourceId, $refHash, $state, ErrorCode::AttachmentUnsupported->value . ($inspection->reason !== null ? ': ' . $inspection->reason : ''));
        }
        if (!$policy->permits($state, $source->visibility)) {
            $reason = $state === PublicationState::Unknown ? ErrorCode::PublicStatusUnknown->value : 'POLICY';
            return $this->skip($sourceId, $refHash, $state, $reason . ($inspection->reason !== null ? ': ' . $inspection->reason : ''));
        }
        $declaredMime = strtolower($inspection->mime ?? $definition->mime);
        if (MimePolicy::isForbidden($declaredMime)) {
            return $this->skip($sourceId, $refHash, $state, ErrorCode::AttachmentUnsupported->value . ': forbidden type');
        }
        $size = $inspection->sizeBytes ?? $definition->sizeBytes;
        if ($size !== null && $size > $policy->maxBytes()) {
            return $this->skip($sourceId, $refHash, $state, ErrorCode::DownloadTooLarge->value);
        }

        $version = $inspection->versionToken ?? $definition->versionToken;
        $current = $this->attachments->forReference($sourceId, $refHash);
        $stored = null;
        foreach ($current as $row) {
            if ($row->syncState === SyncState::Stored && $row->storageKey !== null) {
                $stored = $row;
                break;
            }
        }
        if ($stored !== null && $version !== null && $version === $stored->versionToken && $this->storage->size((string) $stored->storageKey) !== null) {
            // Unchanged file, publication re-verified: refresh the check time only.
            $this->attachments->markStored($sourceId, $refHash, new StoredFile(
                (string) $stored->storageKey,
                $stored->previewKey,
                (string) $stored->sha256,
                $stored->mime,
                $stored->sizeBytes,
                $stored->isImage,
                $stored->filename,
                $stored->versionToken,
            ), $state, $now);
            return 'unchanged';
        }

        $usedBytes = $this->attachments->storedBytes($sourceId);
        if ($usedBytes + (int) $size > $policy->quotaMb * 1024 * 1024 || $this->attachments->storedBytes() + (int) $size > $this->globalQuotaBytes) {
            return $this->skip($sourceId, $refHash, $state, ErrorCode::LimitExceeded->value . ': storage quota');
        }

        $temporary = $this->storage->temporaryFile();
        try {
            $result = $provider->downloadAttachment($connection, $source, $reference, new DownloadTarget($temporary, $policy->maxBytes()));
            clearstatcache(true, $temporary);
            $bytes = is_file($temporary) ? (int) filesize($temporary) : 0;
            if ($bytes === 0) {
                throw new CalendarException(ErrorCode::FetchFailed, 'The downloaded file is empty.');
            }
            if ($bytes > $policy->maxBytes()) {
                throw new CalendarException(ErrorCode::DownloadTooLarge, 'The download exceeds the size limit.');
            }
            $filename = $result->filename ?? $inspection->filename ?? $definition->filename;
            $detected = MimePolicy::reconcile($this->mime->detect($temporary), $declaredMime, $filename);
            if (MimePolicy::isForbidden($detected) || !$policy->allowsMime($detected)) {
                return $this->skip($sourceId, $refHash, $state, ErrorCode::AttachmentUnsupported->value . ': ' . $detected);
            }
            $isImage = MimePolicy::isRasterImage($detected);
            $key = $this->storage->newKey();
            $object = $this->storage->putFromTemp($temporary, $key);
            $previewKey = null;
            if ($isImage && $policy->generateImagePreviews) {
                $previewTemp = $this->storage->temporaryFile();
                try {
                    if ($this->previews->createPreview($this->storage->localPath($key), $previewTemp, $detected)) {
                        $previewKey = $this->storage->newKey('p');
                        $this->storage->putFromTemp($previewTemp, $previewKey);
                    }
                } finally {
                    if (is_file($previewTemp)) {
                        unlink($previewTemp);
                    }
                }
            }
            $superseded = [];
            foreach ($current as $row) {
                foreach ([$row->storageKey, $row->previewKey] as $oldKey) {
                    if ($oldKey !== null && $oldKey !== $key && $oldKey !== $previewKey) {
                        $superseded[$oldKey] = true;
                    }
                }
            }
            $this->attachments->markStored($sourceId, $refHash, new StoredFile(
                $key,
                $previewKey,
                $object->sha256,
                $detected,
                $object->sizeBytes,
                $isImage,
                MimePolicy::downloadName($filename, $detected),
                $result->versionToken ?? $version,
            ), $state, $now);
            // The rows now point to the new file; the old version is no longer referenced and can go at once.
            foreach (array_keys($superseded) as $oldKey) {
                $this->storage->delete((string) $oldKey);
            }
            $this->invalidator->sourceChanged($sourceId);
            return 'stored';
        } catch (CalendarException $e) {
            // The publication was verified just now, so an already stored file may stay deliverable.
            $this->attachments->markFailed($sourceId, $refHash, $e->errorCode->value . ': ' . Redactor::text($e->getMessage(), 120), true, $now);
            $this->logger->log('warning', 'attachment_failed', 'Attachment download failed.', ['source_id' => $sourceId, 'error_code' => $e->errorCode->value]);
            if ($e->isRetryable()) {
                throw $e;
            }
            return 'failed';
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    private function skip(int $sourceId, string $refHash, PublicationState $state, string $reason): string
    {
        $this->attachments->markSkipped($sourceId, $refHash, $state, $reason, $this->clock->now());
        $this->invalidator->sourceChanged($sourceId);
        $this->logger->log('info', 'attachment_skipped', 'Attachment skipped by policy.', ['source_id' => $sourceId, 'reason' => substr($reason, 0, 60)]);
        return 'skipped';
    }
}
