<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'intake_batch_image_id',
    'attempt_no',
    'status',
    'error_type',
    'error_message',
    'model_name',
    'prompt_version',
    'raw_response',
    'started_at',
    'finished_at',
])]
class IntakeImageAttempt extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'raw_response' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function image(): BelongsTo
    {
        return $this->belongsTo(IntakeBatchImage::class, 'intake_batch_image_id');
    }
}