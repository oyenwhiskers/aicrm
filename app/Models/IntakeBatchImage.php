<?php

namespace App\Models;

use App\Enums\IntakeBatchImageStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'intake_batch_id',
    'original_filename',
    'storage_disk',
    'storage_path',
    'sort_order',
    'status',
    'row_count',
    'attempts_count',
    'last_error',
    'metadata',
    'claim_token',
    'claimed_at',
    'claimed_by',
    'started_at',
    'completed_at',
])]
class IntakeBatchImage extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => IntakeBatchImageStatus::class,
            'metadata' => 'array',
            'claimed_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(IntakeBatch::class, 'intake_batch_id');
    }

    public function extractedRows(): HasMany
    {
        return $this->hasMany(IntakeExtractedRow::class);
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(IntakeImageAttempt::class);
    }
}