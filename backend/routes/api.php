<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\BancaController;
use App\Http\Controllers\Api\GrupoController;
use App\Http\Controllers\Api\TaquillaController;
use App\Http\Controllers\Api\ExchangeRateController;
use App\Http\Controllers\Api\JuegoController;
use App\Http\Controllers\Api\ResultadoController;
use App\Http\Controllers\Api\ApuestaController;
use App\Http\Controllers\Api\DispositivoController;
use App\Http\Controllers\Api\ActivacionController;
use App\Http\Controllers\Api\TicketController;
use App\Http\Controllers\Api\PagoController;
use App\Http\Controllers\Api\ReporteController;

// Rutas públicas (sin autenticación)
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,2');
Route::get('/exchange-rate/active', [ExchangeRateController::class, 'active']);
Route::post('/activar', [ActivacionController::class, 'activar'])->middleware('throttle:10,60');
Route::post('/dispositivo/verificar', [DispositivoController::class, 'verificar']);

// Rutas protegidas solo con Sanctum (sin verificación MAC)
Route::middleware(['auth:sanctum'])->group(function () {
    Route::get('/user', [AuthController::class, 'user']);
    Route::post('/logout', [AuthController::class, 'logout']);
});

// Rutas protegidas con Sanctum + verificación MAC
Route::middleware(['auth:sanctum', 'verify.mac'])->group(function () {

    // ==================================================
    // USUARIOS (solo Super Master y Master)
    // ==================================================
    Route::middleware(['role:super_master|master'])->group(function () {
        Route::apiResource('users', UserController::class);
    });

    // ==================================================
    // BANCAS (solo Super Master y Master)
    // ==================================================
    Route::middleware(['role:super_master|master'])->group(function () {
        Route::apiResource('bancas', BancaController::class);
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

    // ==================================================
    // JUEGOS — lectura: cualquier autenticado; modificación: solo master
    // ==================================================
    Route::get('/juegos', [JuegoController::class, 'index']);
    Route::get('/juegos/{juego}', [JuegoController::class, 'show']);

    Route::middleware(['role:super_master|master'])->group(function () {
        Route::put('/juegos/{juego}', [JuegoController::class, 'update']);
        Route::patch('/juegos/{juego}/toggle', [JuegoController::class, 'toggle'])->name('juegos.toggle');
    });

    Route::get('/juegos/{juego}/opciones', [JuegoController::class, 'opciones']);
    Route::get('/juegos/{juego}/horarios', [JuegoController::class, 'horarios']);
    Route::get('/juegos/{juego}/reglas', [JuegoController::class, 'reglas']);

    // ==================================================
    // RESULTADOS (todos los roles autenticados)
    // ==================================================
    Route::get('/resultados', [ResultadoController::class, 'index']);
    Route::get('/resultados/{resultado}', [ResultadoController::class, 'show']);

    // Scraper manual (solo Super Master y Master)
    Route::middleware(['role:super_master|master'])->group(function () {
        Route::post('/resultados/scrape', [ResultadoController::class, 'scrape']);
    Route::post('/resultados/scrape-all', [ResultadoController::class, 'scrapeAll']);
    });
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
        Route::delete('/apuestas/{apuesta}', [ApuestaController::class, 'destroy']);
    });

    // ==================================================
    // LOGS (Super Master, Master)
    // ==================================================
    Route::middleware(['role:super_master|master'])->group(function () {
        Route::get('/logs', function (\Illuminate\Http\Request $request) {
            return \App\Models\Log::with('user')->latest()->paginate($request->input('per_page', 50));
        });
    });

    // ==================================================
    // TICKETS (todos los roles que pueden crear apuestas)
    // ==================================================
    Route::middleware(['role:super_master|master|banca|grupo|taquilla'])->group(function () {
        Route::get('/tickets', [TicketController::class, 'index']);
        Route::post('/tickets', [TicketController::class, 'store']);
        Route::get('/tickets/ganadores', [TicketController::class, 'ganadores']);
        Route::get('/tickets/{ticket}', [TicketController::class, 'show']);
        Route::delete('/tickets/{ticket}', [TicketController::class, 'destroy']);
    });

    // ==================================================
    // PAGOS (todos los roles)
    // ==================================================
    Route::post('/pagos', [PagoController::class, 'store'])->middleware('role:super_master|master|banca|grupo|taquilla');
    Route::get('/pagos/{apuesta}', [PagoController::class, 'showByApuesta'])->middleware('role:super_master|master|banca|grupo|taquilla');

    // ==================================================
    // CIERRE DE CAJA (todos los roles) - PENDIENTE SPRINT 12
    // Route::post('/cierre', [CierreController::class, 'store'])->middleware('role:super_master|master|banca|grupo|taquilla');

    // ==================================================
    // LÍMITES POR JUEGO
    // ==================================================
    // GET: super_master, master, banca, grupo
    Route::middleware(['role:super_master|master|banca|grupo'])->group(function () {
        Route::get('/limites/{juego}', [JuegoController::class, 'limites']);
    });

    // PUT: super_master, master, banca (upsert individual)
    Route::middleware(['role:super_master|master|banca'])->group(function () {
        Route::put('/limites/{juego}', [JuegoController::class, 'updateLimites']);
    });

    // POST batch: solo super_master y master
    Route::middleware(['role:super_master|master'])->group(function () {
        Route::post('/limites/batch', [JuegoController::class, 'batchLimites']);
    });

    // ==================================================
    // REPORTES (todos los roles autenticados)
    // ==================================================
    Route::middleware(['role:super_master|master|banca|grupo|taquilla'])->group(function () {
        Route::get('/reportes/ventas-totales', [ReporteController::class, 'ventasTotales']);
        Route::get('/reportes/relacion-tickets', [ReporteController::class, 'relacionTickets']);
        Route::get('/reportes/rendimiento-taquillas', [ReporteController::class, 'rendimientoTaquillas']);
        Route::get('/reportes/vencidos', [ReporteController::class, 'vencidos']);
    });

    // ==================================================
    // TASAS DE CAMBIO (pública y protegida)
    // ==================================================
    Route::middleware(['permission:view_exchange_rates'])->group(function () {
        Route::get('/exchange-rates', [ExchangeRateController::class, 'index']);
        Route::get('/exchange-rates/{exchange_rate}', [ExchangeRateController::class, 'show']);
    });

    Route::middleware(['permission:manage_exchange_rates'])->group(function () {
        Route::post('/exchange-rates', [ExchangeRateController::class, 'store']);
        Route::post('/exchange-rates/scrape', [ExchangeRateController::class, 'scrape']);
        Route::put('/exchange-rates/{exchange_rate}', [ExchangeRateController::class, 'update']);
        Route::post('/exchange-rates/{exchange_rate}/set-active', [ExchangeRateController::class, 'setActive']);
    });
});
