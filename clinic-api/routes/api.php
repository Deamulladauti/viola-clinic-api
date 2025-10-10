<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Admin\ServiceController;
use App\Http\Controllers\Admin\ServiceCategoryController;

Route::prefix('v1')->group(function () {
    // Public
    Route::get('health', fn () => response()->json(['status' => 'ok']))->name('health');
    Route::post('auth/register', [AuthController::class, 'register']);
    Route::post('auth/login', [AuthController::class, 'login'])->name('login');


    // Protected (Bearer token)
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('auth/me',    [AuthController::class, 'me']);
        Route::post('auth/logout',[AuthController::class, 'logout']);
    });

    // Categories
    Route::get('/categories', [PublicCategoryController::class, 'index']);
    Route::get('/categories/{slug}/services', [PublicCategoryController::class, 'servicesByCategory']);

    // Services
    Route::get('/services', [PublicServiceController::class, 'index']);
    Route::get('/services/{slug}', [PublicServiceController::class, 'show']);
    Route::get('/services/suggest', [PublicServiceController::class, 'suggest']);

    // Optional signal
    Route::post('/signals/services/{slug}/view', [ServiceSignalsController::class, 'view']);

    Route::middleware(['auth:sanctum','role:admin'])->prefix('admin')->group(function () {
    Route::get   ('/categories',               [ServiceCategoryController::class, 'index']);
    Route::post  ('/categories',               [ServiceCategoryController::class, 'store']);
    Route::put   ('/categories/{category}',    [ServiceCategoryController::class, 'update']);
    Route::patch ('/categories/{category}',    [ServiceCategoryController::class, 'update']);
    Route::delete('/categories/{category}',    [ServiceCategoryController::class, 'destroy']);

    Route::get   ('/services',                [ServiceController::class, 'index']);
    Route::post  ('/services',                [ServiceController::class, 'store']);
    Route::get   ('/services/{service}',      [ServiceController::class, 'show']);
    Route::match (['put','patch'], '/services/{service}', [ServiceController::class, 'update']);
    Route::delete('/services/{service}',      [ServiceController::class, 'destroy']);
});
});
