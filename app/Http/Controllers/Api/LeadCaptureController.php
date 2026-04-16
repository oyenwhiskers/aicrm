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
            401, 403 => 'OpenAI rejected the API key. Check OPENAI_API_KEY and OpenAI API access.',
            429 => 'OpenAI rate limit or quota was reached. Wait a moment or use a key with available quota.',
            default => $apiMessage ?: $exception->getMessage(),
        };
    }
}