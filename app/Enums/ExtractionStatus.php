<?php

namespace App\Enums;

enum ExtractionStatus: string
{
    case PENDING = 'pending';
    case PROCESSING = 'processing';
    case COMPLETED = 'completed';
    case FAILED = 'failed';
    case REVIEW_REQUIRED = 'review_required';
}