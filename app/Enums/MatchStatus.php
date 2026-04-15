<?php

namespace App\Enums;

enum MatchStatus: string
{
    case MATCHED = 'matched';
    case CONDITIONAL = 'conditional';
    case NOT_MATCHED = 'not_matched';
    case MANUAL_REVIEW = 'manual_review';
}