<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'bank_id',
    'accepted_sectors',
    'minimum_salary',
    'max_loan_amount',
    'max_dsr',
    'rule_notes',
])]
class BankRule extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'accepted_sectors' => 'array',
            'minimum_salary' => 'decimal:2',
            'max_loan_amount' => 'decimal:2',
            'max_dsr' => 'decimal:2',
            'rule_notes' => 'array',
        ];
    }

    public function bank(): BelongsTo
    {
        return $this->belongsTo(Bank::class);
    }
}