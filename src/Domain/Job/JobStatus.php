<?php

declare(strict_types=1);

namespace SW\Calendar\Domain\Job;

enum JobStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Done = 'done';
    case Failed = 'failed';
}
