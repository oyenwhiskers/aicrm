<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'lead_id',
    'employer',
    'sector',
    'employment_type',
    'salary',
    'other_income',
    'age',
    'years_of_service',
    'is_pensioner',
    'has_akpk',
    'is_blacklisted',
    'has_bnpl',
    'has_legal_or_saa_issue',
])]
class LeadProfile extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'salary' => 'decimal:2',
            'other_income' => 'decimal:2',
            'years_of_service' => 'decimal:2',
            'is_pensioner' => 'boolean',
            'has_akpk' => 'boolean',
            'is_blacklisted' => 'boolean',
            'has_bnpl' => 'boolean',
            'has_legal_or_saa_issue' => 'boolean',
        ];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }
}