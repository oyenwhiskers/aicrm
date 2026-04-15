<?php

namespace App\Models;

use App\Enums\DocumentType;
use App\Enums\ExtractionStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'lead_id',
    'lead_document_id',
    'document_type',
    'extracted_summary',
    'structured_fields',
    'extraction_status',
    'extracted_at',
])]
class LeadExtractedData extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'document_type' => DocumentType::class,
            'extraction_status' => ExtractionStatus::class,
            'structured_fields' => 'array',
            'extracted_at' => 'datetime',
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