<?php

namespace App\Enums;

enum LeadStage: string
{
    case NEW_LEAD = 'NEW_LEAD';
    case CONTACT_READY = 'CONTACT_READY';
    case DOC_REQUESTED = 'DOC_REQUESTED';
    case DOC_PARTIAL = 'DOC_PARTIAL';
    case DOC_COMPLETE = 'DOC_COMPLETE';
    case PROCESSING = 'PROCESSING';
    case PROCESSED = 'PROCESSED';
    case MATCHED = 'MATCHED';
    case NOT_ELIGIBLE = 'NOT_ELIGIBLE';
    case MANUAL_REVIEW = 'MANUAL_REVIEW';
    case CLOSED = 'CLOSED';
}