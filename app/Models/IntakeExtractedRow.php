<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'intake_batch_id',
    'intake_batch_image_id',
    'name',
    'phone_number',
    'source',
    'raw_name',
    'raw_phone_number',
    'confidence',
    'notes',
    'metadata',
])]
class IntakeExtractedRow extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(IntakeBatch::class, 'intake_batch_id');
    }

    public function image(): BelongsTo
    {
        return $this->belongsTo(IntakeBatchImage::class, 'intake_batch_image_id');
    }
}