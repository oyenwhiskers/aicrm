<?php

use App\Http\Controllers\Api\LeadController;
use App\Http\Controllers\Api\LeadBankMatchController;
use App\Http\Controllers\Api\LeadDocumentController;
use App\Http\Controllers\Api\LeadProcessingController;
use App\Http\Controllers\Api\LeadStageController;
use Illuminate\Support\Facades\Route;

Route::prefix('leads')->group(function (): void {
    Route::get('/', [LeadController::class, 'index']);
    Route::post('/', [LeadController::class, 'store']);
    Route::post('/import', [LeadController::class, 'import']);
    Route::get('/{lead}', [LeadController::class, 'show']);
    Route::patch('/{lead}/stage', [LeadStageController::class, 'update']);
    Route::post('/{lead}/documents', [LeadDocumentController::class, 'store']);
    Route::post('/{lead}/calculate', [LeadProcessingController::class, 'calculate']);
    Route::post('/{lead}/match-banks', [LeadBankMatchController::class, 'store']);
});