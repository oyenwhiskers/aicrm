<?php

namespace App\Enums;

enum IntakeBatchImageStatus: string
{
    case QUEUED = 'queued';
    case RETRY_PENDING = 'retry_pending';
    case PROCESSING = 'processing';
    case DONE = 'done';
    case FAILED = 'failed';
}