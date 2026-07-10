<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\GrupoController;
use App\Http\Controllers\Api\TaquillaController;

// Rutas públicas
Route::post('/login', [AuthController::class, 'login']);

// Rutas protegidas con Sanctum
Route::middleware(['auth:sanctum'])->group(function () {
    Route::get('/user', [AuthController::class, 'user']);
    Route::post('/logout', [AuthController::class, 'logout']);

    // ==================================================
    // USUARIOS (solo Super Master y Master)
    // ==================================================
    Route::middleware(['role:super_master|master'])->group(function () {
        Route::apiResource('users', UserController::class);
    });

    // ==================================================
    // GRUPOS (Super Master, Master, Banca)
    // ==================================================
    Route::middleware(['role:super_master|master|banca'])->group(function () {
        Route::apiResource('grupos', GrupoController::class);
    });

    // ==================================================
    // TAQUILLAS (Super Master, Master, Banca, Grupo)
    // ==================================================
    Route::middleware(['role:super_master|master|banca|grupo'])->group(function () {
        Route::apiResource('taquillas', TaquillaController::class);
    });
    });
    // ==================================================
    /*// APUESTAS (todos los roles)
    // ==================================================
    Route::middleware(['role:super_master|master|banca|grupo|taquilla'])->group(function () {
        Route::get('/apuestas', [ApuestaController::class, 'index']);
        Route::post('/apuestas', [ApuestaController::class, 'store']);
        Route::delete('/apuestas/{id}', [ApuestaController::class, 'destroy']);
    });

    // ==================================================
    // PAGOS (todos los roles)
    // ==================================================
    Route::post('/pagos', [PagoController::class, 'store'])->middleware('role:super_master|master|banca|grupo|taquilla');

    // ==================================================
    // CIERRE DE CAJA (todos los roles)
    // ==================================================
    Route::post('/cierre', [CierreController::class, 'store'])->middleware('role:super_master|master|banca|grupo|taquilla');

    // ==================================================
    // TASAS DE CAMBIO (pública y protegida)
    // ==================================================
    Route::get('/exchange-rate/active', [ExchangeRateController::class, 'active']); // pública
    Route::post('/exchange-rates', [ExchangeRateController::class, 'store'])->middleware('role:super_master|master');
});*/
