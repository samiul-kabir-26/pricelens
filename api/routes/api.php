<?php

use App\Http\Controllers\Api\V1\AlertController;
use App\Http\Controllers\Api\V1\ApiKeyController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BroadcastAuthController;
use App\Http\Controllers\Api\V1\CallbackController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\MemberController;
use App\Http\Controllers\Api\V1\MonitorController;
use App\Http\Controllers\Api\V1\SearchController;
use App\Http\Controllers\Api\V1\SearchOfferController;
use App\Http\Controllers\Api\V1\SearchTaskController;
use App\Http\Controllers\Api\V1\SourceController;
use App\Http\Controllers\Api\V1\TrackedProductController;
use App\Http\Controllers\Api\V1\UsageController;
use App\Http\Controllers\Api\V1\WebhookController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    // Unauthenticated probes.
    Route::get('health', [HealthController::class, 'health']);
    Route::get('ready', [HealthController::class, 'ready']);

    // Dashboard users (Sanctum).
    Route::prefix('auth')->group(function () {
        Route::middleware('throttle:auth')->group(function () {
            Route::post('login', [AuthController::class, 'login']);
            Route::post('token', [AuthController::class, 'token']);
            Route::post('register', [AuthController::class, 'register']);
            Route::post('forgot-password', [AuthController::class, 'forgotPassword']);
            Route::post('reset-password', [AuthController::class, 'resetPassword']);
        });
        Route::middleware('auth:sanctum')->group(function () {
            Route::get('me', [AuthController::class, 'me']);
            Route::post('logout', [AuthController::class, 'logout']);
            Route::post('switch-tenant', [AuthController::class, 'switchTenant']);
        });
    });

    Route::middleware(['tenant', 'throttle:api', 'idempotency'])->group(function () {
        Route::post('broadcasting/auth', BroadcastAuthController::class)->middleware('ability:searches:read');

        // Searches
        Route::middleware('ability:searches:read')->group(function () {
            Route::get('searches', [SearchController::class, 'index']);
            Route::get('searches/{search}', [SearchController::class, 'show']);
            Route::get('searches/{search}/offers', [SearchOfferController::class, 'index']);
            Route::get('searches/{search}/tasks', [SearchTaskController::class, 'index']);
            Route::get('searches/{search}/export', [SearchOfferController::class, 'export']);
            Route::get('searches/{search}/image', [SearchController::class, 'image']);

            Route::get('sources', [SourceController::class, 'index']);
            Route::get('sources/global', [SourceController::class, 'global']);
            Route::get('sources/{source}', [SourceController::class, 'show']);

            Route::get('tracked-products', [TrackedProductController::class, 'index']);
            Route::get('tracked-products/{trackedProduct}', [TrackedProductController::class, 'show']);
            Route::get('tracked-products/{trackedProduct}/price-history', [TrackedProductController::class, 'priceHistory']);
            Route::get('monitors', [MonitorController::class, 'index']);
            Route::get('monitors/{monitor}', [MonitorController::class, 'show']);
            Route::get('alerts', [AlertController::class, 'index']);

            Route::get('usage', [UsageController::class, 'show']);
        });

        Route::middleware('ability:searches:write')->group(function () {
            Route::post('searches', [SearchController::class, 'store']);
            Route::post('searches/{search}/cancel', [SearchController::class, 'cancel']);
            Route::post('searches/{search}/rerun', [SearchController::class, 'rerun']);
        });

        Route::middleware('ability:sources:write')->group(function () {
            Route::post('sources', [SourceController::class, 'store']);
            Route::patch('sources/{source}', [SourceController::class, 'update']);
            Route::delete('sources/{source}', [SourceController::class, 'destroy']);
            Route::post('sources/{source}/fork', [SourceController::class, 'fork']);
            Route::post('sources/{source}/test', [SourceController::class, 'test'])->middleware('throttle:source-test');
        });

        Route::middleware('ability:monitors:write')->group(function () {
            Route::post('tracked-products', [TrackedProductController::class, 'store']);
            Route::post('tracked-products/import', [TrackedProductController::class, 'import']);
            Route::patch('tracked-products/{trackedProduct}', [TrackedProductController::class, 'update']);
            Route::delete('tracked-products/{trackedProduct}', [TrackedProductController::class, 'destroy']);
            Route::post('monitors', [MonitorController::class, 'store']);
            Route::patch('monitors/{monitor}', [MonitorController::class, 'update']);
            Route::delete('monitors/{monitor}', [MonitorController::class, 'destroy']);
            Route::post('monitors/{monitor}/run', [MonitorController::class, 'run']);
            Route::post('alerts/{alert}/acknowledge', [AlertController::class, 'acknowledge']);
        });

        Route::middleware('ability:webhooks:write')->group(function () {
            Route::get('webhooks', [WebhookController::class, 'index']);
            Route::post('webhooks', [WebhookController::class, 'store']);
            Route::get('webhooks/{webhook}', [WebhookController::class, 'show']);
            Route::patch('webhooks/{webhook}', [WebhookController::class, 'update']);
            Route::delete('webhooks/{webhook}', [WebhookController::class, 'destroy']);
            Route::post('webhooks/{webhook}/test', [WebhookController::class, 'test']);
            Route::get('webhooks/{webhook}/deliveries', [WebhookController::class, 'deliveries']);
            Route::post('webhooks/{webhook}/deliveries/{delivery}/redeliver', [WebhookController::class, 'redeliver']);
        });

        Route::middleware('ability:admin')->group(function () {
            Route::post('callbacks/secret', [CallbackController::class, 'rotateSecret']);
            Route::get('members', [MemberController::class, 'index']);
            Route::post('members', [MemberController::class, 'store']);
            Route::patch('members/{member}', [MemberController::class, 'update']);
            Route::delete('members/{member}', [MemberController::class, 'destroy']);
            Route::get('callbacks/deliveries', [CallbackController::class, 'deliveries']);
            Route::get('api-keys', [ApiKeyController::class, 'index']);
            Route::post('api-keys', [ApiKeyController::class, 'store']);
            Route::delete('api-keys/{apiKey}', [ApiKeyController::class, 'destroy']);
        });
    });
});
