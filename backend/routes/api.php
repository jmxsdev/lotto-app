<?php

use App\Http\Controllers\Api\ActivacionController;
use App\Http\Controllers\Api\AgenciaController;
use App\Http\Controllers\Api\ApuestaController;
use App\Http\Controllers\Api\AuditEventController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BancaController;
use App\Http\Controllers\Api\CierreController;
use App\Http\Controllers\Api\ClaveCierreController;
use App\Http\Controllers\Api\ComisionController;
use App\Http\Controllers\Api\ConfiguracionController;
use App\Http\Controllers\Api\DispositivoController;
use App\Http\Controllers\Api\EstadisticaController;
use App\Http\Controllers\Api\ExchangeRateController;
use App\Http\Controllers\Api\GrupoController;
use App\Http\Controllers\Api\JuegoController;
use App\Http\Controllers\Api\PagoController;
use App\Http\Controllers\Api\ReleaseController;
use App\Http\Controllers\Api\ReporteController;
use App\Http\Controllers\Api\ResultadoController;
use App\Http\Controllers\Api\TaquillaController;
use App\Http\Controllers\Api\TicketController;
use App\Http\Controllers\Api\UserController;
use App\Http\Middleware\CaptureAuditContext;
use App\Models\Log;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Todas las rutas de la API quedan versionadas bajo el prefijo /api/v1
Route::prefix('v1')->middleware([CaptureAuditContext::class])->group(function () {

    // Rutas públicas (sin autenticación)
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,2');
    Route::get('/exchange-rate/active', [ExchangeRateController::class, 'active']);
    Route::post('/activar', [ActivacionController::class, 'activar'])->middleware('throttle:10,60');
    Route::post('/dispositivo/verificar', [DispositivoController::class, 'verificar']);

    // Serve del instalador: URL firmada (relativa) + throttle; sin auth
    // (la firma ES la credencial, REQ-A2/A3).
    Route::get('/releases/serve', [ReleaseController::class, 'serve'])
        ->middleware(['signed:relative', 'throttle:releases-download'])
        ->name('releases.serve');

    // Update-check (REQ-B1): notificación de versión para la taquilla instalada.
    Route::get('/update-check', [ReleaseController::class, 'updateCheck'])->middleware('throttle:30,1');

    // Feed público electron-updater (TQ-10, D1): latest.yml + .exe + .blockmap
    // de la release vigente. Sin auth ni redirects; octet-stream para que
    // Caddy (encode zstd gzip) nunca comprima. `[^/]+` corta el traversal.
    Route::get('/releases/feed/{file}', [ReleaseController::class, 'feed'])
        ->middleware('throttle:releases-feed')
        ->where('file', '[^/]+');

    // Rutas protegidas solo con Sanctum (sin verificación MAC)
    Route::middleware(['auth:sanctum'])->group(function () {
        Route::get('/user', [AuthController::class, 'user']);
        Route::post('/logout', [AuthController::class, 'logout']);

        // Self-service de la clave de cierre (AD-9): sirve al PANEL, sin
        // headers de dispositivo; fuera de verify.mac como /user y /logout.
        // Roles elegibles master/banca (manage_clave_cierre); otros → 403.
        Route::middleware(['permission:manage_clave_cierre'])->group(function () {
            Route::get('/usuarios/clave-cierre', [ClaveCierreController::class, 'show']);
            Route::put('/usuarios/clave-cierre', [ClaveCierreController::class, 'update']);
        });
    });

    // Rutas protegidas con Sanctum + verificación MAC
    Route::middleware(['auth:sanctum', 'verify.mac'])->group(function () {

        // ==================================================
        // USUARIOS (jerárquico: banca/grupo solo dentro de su alcance,
        // taquilla solo se ve a sí misma; delete acotado a su subárbol por
        // UserPolicy)
        // ==================================================
        Route::apiResource('users', UserController::class)
            ->only(['index', 'show'])->middleware('permission:view_users');
        Route::apiResource('users', UserController::class)
            ->only(['store'])->middleware('permission:create_users');
        Route::apiResource('users', UserController::class)
            ->only(['update'])->middleware('permission:edit_users');
        Route::apiResource('users', UserController::class)
            ->only(['destroy'])->middleware('permission:delete_users');

        // ==================================================
        // BANCAS (view para lectura; manage para escritura)
        // ==================================================
        Route::apiResource('bancas', BancaController::class)
            ->only(['index', 'show'])->middleware('permission:view_bancas');
        Route::apiResource('bancas', BancaController::class)
            ->only(['store', 'update', 'destroy'])->middleware('permission:manage_bancas');
        Route::patch('/bancas/{banca}/toggle', [BancaController::class, 'toggle'])
            ->name('bancas.toggle')->middleware('permission:manage_bancas');

        // ==================================================
        // GRUPOS (view para lectura; manage para escritura)
        // ==================================================
        Route::apiResource('grupos', GrupoController::class)
            ->only(['index', 'show'])->middleware('permission:view_grupos');
        Route::apiResource('grupos', GrupoController::class)
            ->only(['store', 'update', 'destroy'])->middleware('permission:manage_grupos');
        Route::patch('/grupos/{grupo}/toggle', [GrupoController::class, 'toggle'])
            ->name('grupos.toggle')->middleware('permission:manage_grupos');

        // ==================================================
        // AGENCIAS (locales físicos): la agencia recibe manage para editar
        // su propia ficha; el controlador bloquea store/toggle/destroy.
        // ==================================================
        Route::apiResource('agencias', AgenciaController::class)
            ->only(['index', 'show'])->middleware('permission:view_agencias');
        Route::apiResource('agencias', AgenciaController::class)
            ->only(['store', 'update', 'destroy'])->middleware('permission:manage_agencias');
        Route::patch('/agencias/{agencia}/toggle', [AgenciaController::class, 'toggle'])
            ->name('agencias.toggle')->middleware('permission:manage_agencias');

        // ==================================================
        // TAQUILLAS (view para lectura; manage para escritura)
        // ==================================================
        Route::apiResource('taquillas', TaquillaController::class)
            ->only(['index', 'show'])->middleware('permission:view_taquillas');
        Route::apiResource('taquillas', TaquillaController::class)
            ->only(['store', 'update', 'destroy'])->middleware('permission:manage_taquillas');
        Route::patch('/taquillas/{taquilla}/toggle', [TaquillaController::class, 'toggle'])
            ->name('taquillas.toggle')->middleware('permission:manage_taquillas');

        // ==================================================
        // CONFIGURACIONES — ventana de vencimiento sin resultado
        // (REQ13/D5): solo `manage_config` (master vía matriz; super_master
        // por bypass) lee/edita la ventana (GET/PUT).
        // ==================================================
        Route::middleware(['permission:manage_config'])->group(function () {
            Route::get('/configuraciones/apuestas-vencimiento', [ConfiguracionController::class, 'ventanaVencimiento']);
            Route::put('/configuraciones/apuestas-vencimiento', [ConfiguracionController::class, 'actualizarVentanaVencimiento']);
        });

        // ==================================================
        // JUEGOS — lectura: view_juegos (5 roles); modificación: solo
        // manage_juegos (master vía matriz; super_master por bypass)
        // ==================================================
        Route::get('/juegos', [JuegoController::class, 'index'])
            ->middleware('permission:view_juegos');
        Route::get('/juegos/{juego}', [JuegoController::class, 'show'])
            ->middleware('permission:view_juegos');

        Route::middleware(['permission:manage_juegos'])->group(function () {
            Route::put('/juegos/{juego}', [JuegoController::class, 'update']);
            Route::patch('/juegos/{juego}/toggle', [JuegoController::class, 'toggle'])->name('juegos.toggle');
            Route::put('/juegos/{juego}/premios', [JuegoController::class, 'updatePremios'])->name('juegos.premios');
        });

        Route::get('/juegos/{juego}/opciones', [JuegoController::class, 'opciones'])
            ->middleware('permission:view_juegos');
        Route::get('/juegos/{juego}/horarios', [JuegoController::class, 'horarios'])
            ->middleware('permission:view_juegos');
        Route::get('/juegos/{juego}/reglas', [JuegoController::class, 'reglas'])
            ->middleware('permission:view_juegos');

        // ==================================================
        // RESULTADOS — lectura: view_resultados (5 roles); scrapers
        // manuales: manage_resultados (master vía matriz; bypass super_master)
        // ==================================================
        Route::middleware(['permission:view_resultados'])->group(function () {
            Route::get('/resultados', [ResultadoController::class, 'index']);
            Route::get('/resultados/{resultado}', [ResultadoController::class, 'show']);
            Route::get('/resultados/{resultado}/apariciones', [ResultadoController::class, 'apariciones']);
        });

        // Scraper manual (manage_resultados)
        Route::middleware(['permission:manage_resultados'])->group(function () {
            Route::post('/resultados/scrape', [ResultadoController::class, 'scrape']);
            Route::post('/resultados/scrape-all', [ResultadoController::class, 'scrapeAll']);
        });
        // ==================================================
        // ==================================================
        // APUESTAS — lectura: view_apuestas (5 roles). Alta: create_apuesta
        // (sin agencia, matriz final). Anulación: delete_apuesta (agencia sí).
        // ==================================================
        Route::middleware(['permission:view_apuestas'])->group(function () {
            Route::get('/apuestas', [ApuestaController::class, 'index']);
            Route::get('/apuestas/historial', [ApuestaController::class, 'historial']);
            Route::get('/apuestas/resumen', [ApuestaController::class, 'resumen']);
            Route::get('/apuestas/{apuesta}', [ApuestaController::class, 'show']);
        });

        Route::post('/apuestas', [ApuestaController::class, 'store'])
            ->middleware('permission:create_apuesta');

        Route::delete('/apuestas/{apuesta}', [ApuestaController::class, 'destroy'])
            ->middleware('permission:delete_apuesta');

        // ==================================================
        // LOGS — view_logs (master vía matriz; super_master por bypass)
        // ==================================================
        Route::middleware(['permission:view_logs'])->group(function () {
            Route::get('/logs', function (Request $request) {
                return Log::with('user')->latest()->paginate($request->input('per_page', 50));
            });
        });

        // ==================================================
        // AUDITORÍA — lectura con cursor (view_audit: master vía matriz;
        // super_master por bypass). El scope de filas lo acota
        // AlcanceService::applyToAuditoria; el export queda para WU-9b.
        // ==================================================
        Route::middleware(['permission:view_audit'])->group(function () {
            Route::get('/audit-events', [AuditEventController::class, 'index']);
        });

        // ==================================================
        // TICKETS — gate de capability `create_apuesta`: agencia queda fuera
        // (sin ese permiso) para no despertar el scoping de filas, que no la
        // filtra. master/banca/grupo/taquilla conservan el acceso previo.
        // ==================================================
        Route::middleware(['permission:create_apuesta'])->group(function () {
            Route::get('/tickets', [TicketController::class, 'index']);
            Route::post('/tickets', [TicketController::class, 'store']);
            Route::get('/tickets/ganadores', [TicketController::class, 'ganadores']);
            Route::get('/tickets/{ticket}', [TicketController::class, 'show']);
            Route::delete('/tickets/{ticket}', [TicketController::class, 'destroy']);
        });

        // ==================================================
        // PAGOS — alta: create_pago; consulta: view_pagos (sin agencia)
        // ==================================================
        Route::post('/pagos', [PagoController::class, 'store'])->middleware('permission:create_pago');
        Route::get('/pagos/{apuesta}', [PagoController::class, 'showByApuesta'])->middleware('permission:view_pagos');

        // ==================================================
        // CIERRE DE CAJA (jerárquico: la agencia cierra su propia caja;
        // los roles administrativos cierran agencias dentro de su alcance)
        // ==================================================
        // POST: create_cierre (5 roles). GET: view_cierre (5 roles).
        Route::post('/cierre', [CierreController::class, 'store'])
            ->middleware('permission:create_cierre');

        Route::middleware(['permission:view_cierre'])->group(function () {
            Route::get('/cierre', [CierreController::class, 'index']);
            // AD-11: actual y semanal ANTES de /cierre/{cierre} para que el
            // route-model binding no capture "actual"/"semanal" (404).
            Route::get('/cierre/actual', [CierreController::class, 'actual']);
            Route::get('/cierre/semanal', [CierreController::class, 'semanal']);
            Route::get('/cierre/{cierre}', [CierreController::class, 'show']);
        });

        // ==================================================
        // LÍMITES POR JUEGO
        // ==================================================
        // GET: view_limites (master, banca, grupo, agencia)
        Route::middleware(['permission:view_limites'])->group(function () {
            // GET /api/v1/limites (modo entidad): matriz completa de una entidad
            // (el conteo de segmentos lo desambigua de /limites/{juego})
            Route::get('/limites', [JuegoController::class, 'listarLimites']);
            Route::get('/limites/{juego}', [JuegoController::class, 'limites']);
        });

        // PUT: manage_limites (master, banca, grupo; upsert individual; el
        // grupo solo escribe dentro de su subárbol, validado en el controlador)
        // DELETE: idem (autorización jerárquica en el controlador)
        Route::middleware(['permission:manage_limites'])->group(function () {
            Route::put('/limites/{juego}', [JuegoController::class, 'updateLimites']);
            Route::delete('/limites/{limite}', [JuegoController::class, 'destroyLimite']);
        });

        // POST batch: manage_limites (el alcance se valida por jerarquía en el controlador)
        Route::middleware(['permission:manage_limites'])->group(function () {
            Route::post('/limites/batch', [JuegoController::class, 'batchLimites']);
        });

        // ==================================================
        // COMISIONES — default global (2 filas bs/usd, D1)
        // GET/PUT: manage_comisiones, sin rol asignado ⇒ solo super_master
        // por bypass. Ruta estática ANTES de cualquier {comision} futura.
        // ==================================================
        Route::middleware(['permission:manage_comisiones'])->group(function () {
            Route::get('/comisiones/defaults', [ComisionController::class, 'defaults']);
            Route::put('/comisiones/defaults', [ComisionController::class, 'updateDefaults']);
        });

        // ==================================================
        // COMISIONES — consulta read-only por rango (Modelo A)
        // GET: view_comisiones (master); el master se limita a
        // masterBancaIds en el controlador.
        // ==================================================
        Route::middleware(['permission:view_comisiones'])->group(function () {
            Route::get('/comisiones/consulta', [ComisionController::class, 'consulta']);
        });

        // ==================================================
        // REPORTES — view_reports (5 roles del panel)
        // ==================================================
        Route::middleware(['permission:view_reports'])->group(function () {
            Route::get('/reportes/ventas-totales', [ReporteController::class, 'ventasTotales']);
            Route::get('/reportes/cuadre-caja', [ReporteController::class, 'cuadreCaja']);
            Route::get('/reportes/relacion-tickets', [ReporteController::class, 'relacionTickets']);
            Route::get('/reportes/vencidos', [ReporteController::class, 'vencidos']);
        });

        // ==================================================
        // ESTADÍSTICAS — view_reports (5 roles del panel)
        // ==================================================
        Route::middleware(['permission:view_reports'])->group(function () {
            Route::get('/estadisticas/rendimiento', [EstadisticaController::class, 'rendimiento']);
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

        // ==================================================
        // DISTRIBUCIÓN DE TAQUILLA (releases del instalador)
        // latest/download: download_release (5 roles, taquilla incluida:
        // cambio deliberado BE-04). serve: URL firmada, fuera de auth.
        // ==================================================
        Route::middleware(['permission:download_release'])->group(function () {
            Route::get('/releases/latest', [ReleaseController::class, 'latest']);
            Route::get('/releases/download', [ReleaseController::class, 'download'])->middleware('throttle:releases-download');
        });
    }); // fin Route::prefix('v1')
});
