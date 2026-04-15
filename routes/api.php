<?php

use App\Http\Controllers\Api\LeadController;
use App\Http\Controllers\Api\LeadBankMatchController;
use App\Http\Controllers\Api\LeadCaptureController;
use App\Http\Controllers\Api\LeadDocumentController;
use App\Http\Controllers\Api\LeadProcessingController;
use App\Http\Controllers\Api\LeadStageController;
use Illuminate\Support\Facades\Route;

Route::post('/lead-intake/extract-image', [LeadCaptureController::class, 'extract']);

Route::prefix('leads')->group(function (): void {
    Route::get('/', [LeadController::class, 'index']);
    Route::post('/', [LeadController::class, 'store']);
    Route::post('/import', [LeadController::class, 'import']);
    Route::delete('/{lead}', [LeadController::class, 'destroy']);
    Route::get('/{lead}', [LeadController::class, 'show']);
    Route::get('/{lead}/documents/status', [LeadDocumentController::class, 'status']);
    Route::get('/{lead}/documents/{document}/preview', [LeadDocumentController::class, 'preview']);
    Route::patch('/{lead}/stage', [LeadStageController::class, 'update']);
    Route::post('/{lead}/documents/batch', [LeadDocumentController::class, 'storeBatch']);
    Route::post('/{lead}/documents', [LeadDocumentController::class, 'store']);
    Route::patch('/{lead}/documents/{document}/assignment', [LeadDocumentController::class, 'updateAssignment']);
    Route::delete('/{lead}/documents/{document}', [LeadDocumentController::class, 'destroy']);
    Route::post('/{lead}/calculate', [LeadProcessingController::class, 'calculate']);
    Route::post('/{lead}/match-banks', [LeadBankMatchController::class, 'store']);
});