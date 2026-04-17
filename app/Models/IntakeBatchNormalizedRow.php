<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'intake_batch_id',
    'name',
    'phone_number',
    'source',
    'confidence',
    'notes',
    'source_images',
    'metadata',
])]
class IntakeBatchNormalizedRow extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'source_images' => 'array',
            'metadata' => 'array',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(IntakeBatch::class, 'intake_batch_id');
    }
}