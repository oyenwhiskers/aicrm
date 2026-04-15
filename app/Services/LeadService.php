<?php

namespace App\Services;

use App\Enums\LeadStage;
use App\Models\Lead;
use Illuminate\Validation\ValidationException;

class LeadService
{
    public function __construct(
        protected ActivityLogService $activityLogService,
    ) {
    }

    public function create(array $attributes, string $origin = 'manual'): Lead
    {
        $duplicate = $this->findDuplicate($attributes['phone_number'], $attributes['ic_number'] ?? null);

        if ($duplicate !== null) {
            throw ValidationException::withMessages([
                'phone_number' => ['A lead with the same phone number or IC number already exists.'],
            ]);
        }

        $lead = Lead::query()->create([
            'name' => $attributes['name'],
            'phone_number' => $attributes['phone_number'],
            'ic_number' => $attributes['ic_number'] ?? null,
            'source' => $attributes['source'] ?? null,
            'stage' => $attributes['stage'] ?? LeadStage::NEW_LEAD,
        ]);

        $lead->profile()->create();
        $lead->stageHistories()->create([
            'old_stage' => null,
            'new_stage' => $lead->stage,
            'changed_at' => now(),
            'note' => 'Lead created',
        ]);

        $this->activityLogService->log(
            $lead,
            'lead.created',
            'Lead created.',
            ['origin' => $origin]
        );

        return $lead->fresh(['profile']);
    }

    public function import(array $rows): array
    {
        $created = collect();
        $duplicates = collect();

        foreach ($rows as $row) {
            $duplicate = $this->findDuplicate($row['phone_number'], $row['ic_number'] ?? null);

            if ($duplicate !== null) {
                $duplicates->push([
                    'input' => $row,
                    'existing_lead_id' => $duplicate->id,
                ]);

                continue;
            }

            $created->push($this->create($row, 'import'));
        }

        return [
            'created' => $created,
            'duplicates' => $duplicates,
        ];
    }

    protected function findDuplicate(string $phoneNumber, ?string $icNumber = null): ?Lead
    {
        return Lead::query()
            ->when(
                $icNumber,
                fn ($query) => $query->where('phone_number', $phoneNumber)->orWhere('ic_number', $icNumber),
                fn ($query) => $query->where('phone_number', $phoneNumber)
            )
            ->first();
    }
}