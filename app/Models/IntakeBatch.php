<?php

namespace App\Models;

use App\Enums\IntakeBatchStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'source',
    'status',
    'total_images',
    'processed_images',
    'successful_images',
    'failed_images',
    'total_rows',
    'metadata',
    'started_at',
    'completed_at',
])]
class IntakeBatch extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => IntakeBatchStatus::class,
            'metadata' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function images(): HasMany
    {
        return $this->hasMany(IntakeBatchImage::class);
    }

    public function extractedRows(): HasMany
    {
        return $this->hasMany(IntakeExtractedRow::class);
    }

    public function normalizedRows(): HasMany
    {
        return $this->hasMany(IntakeBatchNormalizedRow::class);
    }
}