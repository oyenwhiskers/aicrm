<?php

namespace App\Models;

use App\Enums\LeadStage;
use Illuminate\Database\Eloquent\Attributes\Cast;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'lead_id',
    'old_stage',
    'new_stage',
    'changed_at',
    'note',
])]
class LeadStageHistory extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected function oldStage(): Cast
    {
        return Cast::of(LeadStage::class);
    }

    protected function newStage(): Cast
    {
        return Cast::of(LeadStage::class);
    }

    protected function casts(): array
    {
        return [
            'changed_at' => 'datetime',
        ];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }
}