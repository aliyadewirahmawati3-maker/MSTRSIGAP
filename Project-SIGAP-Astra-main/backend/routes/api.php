<?php

use App\Http\Controllers\EvpController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\HeuristicController;
use App\Http\Controllers\IntersectionController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes - SIGAP Backend
|--------------------------------------------------------------------------
| Seluruh rute API publik dan internal untuk komunikasi dashboard,
| FastAPI AI service, dan simulator fase lampu lalu lintas adaptif.
*/

// Endpoint Kesehatan & Konektivitas Database
Route::get('/health', [HealthController::class, 'check'])->name('api.health');

// REST API Konfigurasi Simpang & Keputusan Heuristik
Route::prefix('intersections')->name('api.intersections.')->group(function () {
    Route::get('/', [IntersectionController::class, 'index'])->name('index');
    Route::get('/{id}', [IntersectionController::class, 'show'])->name('show');
    Route::get('/{id}/approaches', [IntersectionController::class, 'approaches'])->name('approaches');
    Route::get('/{id}/cameras', [IntersectionController::class, 'cameras'])->name('cameras');
    Route::get('/{id}/system-status', [IntersectionController::class, 'systemStatus'])->name('system-status');
    Route::get('/{id}/signal-phases', [IntersectionController::class, 'signalPhases'])->name('signal-phases');

    // Endpoint Keputusan Heuristik & DSS (Tahap 5)
    Route::get('/{id}/heuristic-decisions/latest', [HeuristicController::class, 'latest'])->name('heuristic.latest');
    Route::get('/{id}/heuristic-decisions', [HeuristicController::class, 'history'])->name('heuristic.history');
    Route::post('/{id}/evaluate-heuristic', [HeuristicController::class, 'evaluate'])->name('heuristic.evaluate');

    // Endpoint Emergency Vehicle Priority (EVP - Tahap 7)
    Route::post('/{id}/evp/trigger', [EvpController::class, 'trigger'])->name('evp.trigger');
    Route::post('/{id}/evp/cancel', [EvpController::class, 'cancel'])->name('evp.cancel');
    Route::post('/{id}/evp/complete', [EvpController::class, 'complete'])->name('evp.complete');
    Route::get('/{id}/evp/status', [EvpController::class, 'status'])->name('evp.status');
    Route::get('/{id}/evp/logs', [EvpController::class, 'logs'])->name('evp.logs');
});

// Fallback root status API
Route::get('/', function () {
    return response()->json([
        'message' => 'SIGAP Backend API is running',
        'message' => 'SIGAP Backend REST API is running',
        'version' => '0.2.0-phase2',
        'health_check' => url('/api/health'),
        'endpoints' => [
            'intersections' => url('/api/intersections'),
        ],
    ]);
});

