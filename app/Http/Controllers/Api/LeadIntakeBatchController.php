<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreLeadIntakeBatchRequest;
use App\Models\IntakeBatch;
use App\Services\IntakeBatchService;
use Illuminate\Http\JsonResponse;

class LeadIntakeBatchController extends Controller
{
    public function store(StoreLeadIntakeBatchRequest $request, IntakeBatchService $intakeBatchService): JsonResponse
    {
        $batch = $intakeBatchService->createBatch(
            $request->file('images', []),
            $request->validated('source'),
            $request->validated('client_keys', []),
            $request->validated('image_metadata', []),
        );

        return response()->json([
            'data' => $intakeBatchService->statusPayload($batch->fresh()),
        ], 202);
    }

    public function show(IntakeBatch $batch, IntakeBatchService $intakeBatchService): JsonResponse
    {
        return response()->json([
            'data' => $intakeBatchService->statusPayload($batch),
        ]);
    }
}