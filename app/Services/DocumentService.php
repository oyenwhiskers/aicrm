<?php

namespace App\Services;

use App\Enums\UploadStatus;
use App\Models\Lead;
use App\Models\LeadDocument;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class DocumentService
{
    public function storeAndRegister(Lead $lead, UploadedFile $file, string $documentType, string $disk = 'public'): LeadDocument
    {
        $path = $file->store("leads/{$lead->id}/documents/{$documentType}", $disk);

        if ($path === false) {
            throw new \RuntimeException('Unable to store uploaded file.');
        }

        return $lead->documents()->create([
            'document_type' => $documentType,
            'original_filename' => $file->getClientOriginalName(),
            'storage_disk' => $disk,
            'storage_path' => $path,
            'upload_status' => UploadStatus::UPLOADED,
            'uploaded_at' => now(),
            'metadata' => [
                'size' => $file->getSize(),
                'mime_type' => $file->getMimeType(),
                'public_url' => Storage::disk($disk)->url($path),
            ],
        ]);
    }
}