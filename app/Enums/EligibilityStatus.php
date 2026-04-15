<?php

namespace App\Enums;

enum EligibilityStatus: string
{
    case INCOMPLETE = 'incomplete';
    case ELIGIBLE = 'eligible';
    case NOT_ELIGIBLE = 'not_eligible';
    case MANUAL_REVIEW = 'manual_review';
}