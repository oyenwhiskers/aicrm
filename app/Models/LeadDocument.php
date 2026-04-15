<?php

namespace App\Models;

use App\Enums\DocumentType;
use App\Enums\UploadStatus;
use Illuminate\Database\Eloquent\Attributes\Cast;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'lead_id',
    'document_type',
    'original_filename',
    'storage_disk',
    'storage_path',
    'upload_status',
    'uploaded_at',
    'metadata',
])]
class LeadDocument extends Model
{
    use HasFactory;

    protected function documentType(): Cast
    {
        return Cast::of(DocumentType::class);
    }

    protected function uploadStatus(): Cast
    {
        return Cast::of(UploadStatus::class);
    }

    protected function casts(): array
    {
        return [
            'uploaded_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function extractedData(): HasMany
    {
        return $this->hasMany(LeadExtractedData::class);
    }
}