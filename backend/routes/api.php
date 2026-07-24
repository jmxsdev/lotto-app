<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\GrupoController;
use App\Http\Controllers\Api\TaquillaController;
use App\Http\Controllers\Api\ExchangeRateController;
use App\Http\Controllers\Api\JuegoController;
use App\Http\Controllers\Api\ResultadoController;
use App\Http\Controllers\Api\ApuestaController;

// Rutas públicas
Route::post('/login', [AuthController::class, 'login']);
Route::get('/exchange-rate/active', [ExchangeRateController::class, 'active']); // pública

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

    Route::middleware(['role:super_master|master'])->group(function () {
        Route::apiResource('juegos', JuegoController::class);
        Route::patch('/juegos/{juego}/toggle', [JuegoController::class, 'toggle'])->name('juegos.toggle');
    });

    // ==================================================
    // RESULTADOS (todos los roles autenticados)
    // ==================================================
    Route::get('/resultados', [ResultadoController::class, 'index']);
    Route::get('/resultados/{resultado}', [ResultadoController::class, 'show']);
    // ==================================================
    // ==================================================
    // APUESTAS (todos los roles)
    // ==================================================
    Route::middleware(['role:super_master|master|banca|grupo|taquilla'])->group(function () {
        Route::get('/apuestas', [ApuestaController::class, 'index']);
        Route::post('/apuestas', [ApuestaController::class, 'store']);
        Route::get('/apuestas/historial', [ApuestaController::class, 'historial']);
        Route::get('/apuestas/resumen', [ApuestaController::class, 'resumen']);
        Route::get('/apuestas/{apuesta}', [ApuestaController::class, 'show']);
    });

    // ==================================================
    // PAGOS (todos los roles) - PENDIENTE SPRINT 9
    // Route::post('/pagos', [PagoController::class, 'store'])->middleware('role:super_master|master|banca|grupo|taquilla');

    // ==================================================
    // CIERRE DE CAJA (todos los roles) - PENDIENTE SPRINT 12
    // Route::post('/cierre', [CierreController::class, 'store'])->middleware('role:super_master|master|banca|grupo|taquilla');

    // ==================================================
    // TASAS DE CAMBIO (pública y protegida)
    // ==================================================
    Route::middleware(['permission:view_exchange_rates'])->group(function () {
        Route::get('/exchange-rates', [ExchangeRateController::class, 'index']);
        Route::get('/exchange-rates/{exchange_rate}', [ExchangeRateController::class, 'show']);
    });

    Route::middleware(['permission:manage_exchange_rates'])->group(function () {
        Route::post('/exchange-rates', [ExchangeRateController::class, 'store']);
        Route::put('/exchange-rates/{exchange_rate}', [ExchangeRateController::class, 'update']);
        Route::delete('/exchange-rates/{exchange_rate}', [ExchangeRateController::class, 'destroy']);
        Route::post('/exchange-rates/{exchange_rate}/set-active', [ExchangeRateController::class, 'setActive']);
    });
});
