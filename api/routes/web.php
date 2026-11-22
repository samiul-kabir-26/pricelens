<?php

use App\Http\Controllers\Api\V1\HealthController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->json(['service' => 'PriceLens API', 'docs' => '/api/v1', 'health' => '/api/v1/health']));

// Prometheus scrape endpoint (optionally protected by PRICELENS_METRICS_TOKEN).
Route::get('/metrics', [HealthController::class, 'metrics']);
