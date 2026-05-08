<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\API\AuthController;
use App\Http\Controllers\API\PlantController;
use App\Http\Controllers\API\ClassificationController;
use App\Http\Controllers\API\PredictionController;

Route::post('/login', [AuthController::class, 'login']);
Route::post('/users', [AuthController::class, 'register']);

Route::post('/predict', [PredictionController::class, 'predict']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/plants', [PlantController::class, 'index']);
    Route::get('/classifications', [ClassificationController::class, 'index']);
    // Route::post('/predict', [PredictionController::class, 'predict']);
    Route::post('/process-with-filters', [PredictionController::class, 'processWithFilters']);
    Route::put('/users/{id}/password', [AuthController::class, 'updatePassword']);
    Route::get('/health', function () {
        return response()->json(['status' => 'ok']);
    });
});
