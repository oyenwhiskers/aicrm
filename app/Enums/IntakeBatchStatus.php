<?php

namespace App\Enums;

enum IntakeBatchStatus: string
{
    case QUEUED = 'queued';
    case PROCESSING = 'processing';
    case COMPLETED = 'completed';
    case COMPLETED_WITH_FAILURES = 'completed_with_failures';
    case FAILED = 'failed';
}