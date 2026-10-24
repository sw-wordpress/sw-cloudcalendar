<?php

declare(strict_types=1);

namespace SW\Calendar\Domain\Job;

enum JobType: string
{
    case SyncSource = 'sync_source';
    case SyncAttachment = 'sync_attachment';
    case PruneStorage = 'prune_storage';
    case RebuildCache = 'rebuild_cache';
    case RotateLogs = 'rotate_logs';
}
