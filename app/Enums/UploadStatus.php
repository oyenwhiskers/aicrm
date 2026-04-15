<?php

namespace App\Enums;

enum UploadStatus: string
{
    case UPLOADED = 'uploaded';
    case PROCESSING = 'processing';
    case FAILED = 'failed';
}