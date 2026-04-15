<?php

namespace App\Models;

use App\Enums\LeadStage;
use Illuminate\Database\Eloquent\Attributes\Cast;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'name',
    'phone_number',
    'ic_number',
    'source',
    'stage',
])]
class Lead extends Model
{
    use HasFactory;

    protected function stage(): Cast
    {
        return Cast::of(LeadStage::class);
    }

    public function profile(): HasOne
    {
        return $this->hasOne(LeadProfile::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(LeadDocument::class);
    }

    public function extractedData(): HasMany
    {
        return $this->hasMany(LeadExtractedData::class);
    }

    public function calculationResults(): HasMany
    {
        return $this->hasMany(LeadCalculationResult::class);
    }

    public function bankMatches(): HasMany
    {
        return $this->hasMany(LeadBankMatch::class);
    }

    public function activityLogs(): HasMany
    {
        return $this->hasMany(LeadActivityLog::class);
    }

    public function stageHistories(): HasMany
    {
        return $this->hasMany(LeadStageHistory::class);
    }
}