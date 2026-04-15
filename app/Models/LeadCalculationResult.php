<?php

namespace App\Models;

use App\Enums\CalculationStatus;
use App\Enums\EligibilityStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'lead_id',
    'total_recognized_income',
    'total_commitments',
    'dsr_result',
    'allowed_financing_amount',
    'installment',
    'payout_result',
    'eligibility_status',
    'calculation_status',
    'input_snapshot',
    'result_breakdown',
    'processed_at',
])]
class LeadCalculationResult extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'eligibility_status' => EligibilityStatus::class,
            'calculation_status' => CalculationStatus::class,
            'total_recognized_income' => 'decimal:2',
            'total_commitments' => 'decimal:2',
            'dsr_result' => 'decimal:2',
            'allowed_financing_amount' => 'decimal:2',
            'installment' => 'decimal:2',
            'payout_result' => 'decimal:2',
            'input_snapshot' => 'array',
            'result_breakdown' => 'array',
            'processed_at' => 'datetime',
        ];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }
}