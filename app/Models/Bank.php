<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'name',
    'code',
    'is_active',
])]
class Bank extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function rule(): HasOne
    {
        return $this->hasOne(BankRule::class);
    }

    public function leadMatches(): HasMany
    {
        return $this->hasMany(LeadBankMatch::class);
    }
}