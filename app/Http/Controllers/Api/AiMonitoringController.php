<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AiMonitoringService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AiMonitoringController extends Controller
{
    public function __construct(
        protected AiMonitoringService $aiMonitoringService,
    ) {
    }

    public function overview(Request $request): JsonResponse
    {
        $filters = $this->aiMonitoringService->normalizedFilters($request->all());

        return response()->json([
            'data' => $this->aiMonitoringService->overview($filters),
        ]);
    }

    public function breakdowns(Request $request): JsonResponse
    {
        $filters = $this->aiMonitoringService->normalizedFilters($request->all());

        return response()->json([
            'data' => $this->aiMonitoringService->breakdowns($filters),
        ]);
    }

    public function requests(Request $request): JsonResponse
    {
        $filters = $this->aiMonitoringService->normalizedFilters($request->all());
        $perPage = $request->integer('per_page', 25);

        return response()->json(
            $this->aiMonitoringService->requests($filters, $perPage)
        );
    }

    public function destroyLogs(): JsonResponse
    {
        $deletedCount = $this->aiMonitoringService->deleteAllLogs();

        return response()->json([
            'message' => 'AI monitoring records deleted successfully.',
            'data' => [
                'deleted_count' => $deletedCount,
            ],
        ]);
    }
}
