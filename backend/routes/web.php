<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Admin\Auth\AuthController;
use App\Http\Controllers\Admin\ResultadoController;

Route::get('/', function () {
    return view('welcome');
});

// Rutas públicas de login
Route::get('/admin/login', [AuthController::class, 'showLoginForm'])->name('admin.login');
Route::post('/admin/login', [AuthController::class, 'login'])->name('admin.login.submit');
Route::post('/admin/logout', [AuthController::class, 'logout'])->name('admin.logout');

// Rutas protegidas del dashboard admin
Route::middleware(['web', 'auth'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/resultados', [ResultadoController::class, 'index'])->name('resultados.index');
    Route::post('/resultados/scrape', [ResultadoController::class, 'scrape'])->name('resultados.scrape');
    
    // Middleware adicional de rol
    Route::middleware(['role:super_master|master'])->group(function () {
        // Las rutas ya están definidas arriba, este bloque es para futuras expansiones
    });
});
