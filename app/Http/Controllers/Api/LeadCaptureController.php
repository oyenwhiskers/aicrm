<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ExtractLeadImageRequest;
use App\Services\LeadCaptureService;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Throwable;

class LeadCaptureController extends Controller
{
    public function extract(ExtractLeadImageRequest $request, LeadCaptureService $leadCaptureService): JsonResponse
    {
        // Local PHP often defaults to 30s, which is shorter than the upstream AI timeout/retry window.
        if (function_exists('set_time_limit')) {
            @set_time_limit(180);
        }

        @ini_set('max_execution_time', '180');

        try {
            $result = $leadCaptureService->extractFromImage(
                $request->file('image'),
                $request->validated('source')
            );

            return response()->json([
                'data' => $result,
            ]);
        } catch (RequestException $exception) {
            report($exception);

            return response()->json([
                'message' => $this->messageFromRequestException($exception),
            ], $exception->response?->status() ?? 502);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => $exception->getMessage(),
            ], 502);
        }
    }

    protected function messageFromRequestException(RequestException $exception): string
    {
        $status = $exception->response?->status();
        $payload = $exception->response?->json();
        $apiMessage = data_get($payload, 'error.message');

        return match ($status) {
            401, 403 => 'Gemini rejected the API key. Check GEMINI_API_KEY and the project access for this model.',
            429 => 'Gemini is currently rate-limited or under high demand. Wait a moment and try again, or upload fewer images per batch.',
            500, 502, 503, 504 => 'Gemini is temporarily overloaded. Wait a moment and try again. Smaller batches usually work better.',
            default => $apiMessage ?: $exception->getMessage(),
        };
    }
}