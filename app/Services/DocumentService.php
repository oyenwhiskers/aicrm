<?php

namespace App\Services;

use App\Enums\DocumentType;
use App\Enums\UploadStatus;
use App\Models\Lead;
use App\Models\LeadDocument;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class DocumentService
{
    public function registerUploadedFile(Lead $lead, UploadedFile $file, string $disk = 'public'): LeadDocument
    {
        return $this->storeAndRegister($lead, $file, DocumentType::OTHER->value, null, $disk);
    }

    public function storeAndRegister(Lead $lead, UploadedFile $file, string $documentType, ?string $documentSlot = null, string $disk = 'public'): LeadDocument
    {
        $pathSuffix = $documentSlot ? "{$documentType}/{$documentSlot}" : $documentType;
        $path = $file->store("leads/{$lead->id}/documents/{$pathSuffix}", $disk);

        if ($path === false) {
            throw new \RuntimeException('Unable to store uploaded file.');
        }

        return $lead->documents()->create([
            'document_type' => $documentType,
            'original_filename' => $file->getClientOriginalName(),
            'storage_disk' => $disk,
            'storage_path' => $path,
            'upload_status' => UploadStatus::QUEUED,
            'uploaded_at' => now(),
            'metadata' => [
                'document_slot' => $documentSlot,
                'size' => $file->getSize(),
                'mime_type' => $file->getMimeType(),
                'public_url' => Storage::disk($disk)->url($path),
            ],
        ]);
    }

    public function deleteRegisteredDocument(LeadDocument $document): void
    {
        if (filled($document->storage_disk) && filled($document->storage_path)) {
            Storage::disk($document->storage_disk)->delete($document->storage_path);
        }

        $document->delete();
    }
}