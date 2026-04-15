<?php

namespace App\Enums;

enum UploadStatus: string
{
    case QUEUED = 'queued';
    case UPLOADED = 'uploaded';
    case PROCESSING = 'processing';
    case FAILED = 'failed';
    case DELETING = 'deleting';
}