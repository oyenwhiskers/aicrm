<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'provider',
    'request_context',
    'model',
    'lead_id',
    'lead_document_id',
    'document_type',
    'input_mime_type',
    'input_filename',
    'input_tokens',
    'output_tokens',
    'total_tokens',
    'estimated_cost',
    'latency_ms',
    'http_status',
    'request_status',
    'needs_review',
    'review_reasons',
    'error_code',
    'error_message',
    'request_started_at',
    'request_finished_at',
])]
class AiUsageLog extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'review_reasons' => 'array',
            'needs_review' => 'boolean',
            'estimated_cost' => 'decimal:6',
            'request_started_at' => 'datetime',
            'request_finished_at' => 'datetime',
        ];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(LeadDocument::class, 'lead_document_id');
    }
}
