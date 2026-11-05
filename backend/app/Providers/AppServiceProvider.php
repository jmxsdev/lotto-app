<?php

namespace App\Providers;

use App\Models\Agencia;
use App\Models\Banca;
use App\Models\CierreCaja;
use App\Models\Grupo;
use App\Models\Taquilla;
use App\Models\Ticket;
use App\Models\User;
use App\Policies\AgenciaPolicy;
use App\Policies\BancaPolicy;
use App\Policies\CierrePolicy;
use App\Policies\GrupoPolicy;
use App\Policies\TaquillaPolicy;
use App\Policies\TicketPolicy;
use App\Policies\UserPolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Spatie\Permission\PermissionRegistrar;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Schema::defaultStringLength(191);

        // Autorización jerárquica de usuarios (issue #84): registro explícito
        // de la policy, además del auto-discovery de Laravel.
        Gate::policy(User::class, UserPolicy::class);

        // Policies de alcance (issue #85). Registro explícito: `CierreCaja`
        // no se resuelve por auto-discovery (`CierrePolicy`).
        Gate::policy(Banca::class, BancaPolicy::class);
        Gate::policy(Grupo::class, GrupoPolicy::class);
        Gate::policy(Agencia::class, AgenciaPolicy::class);
        Gate::policy(Taquilla::class, TaquillaPolicy::class);
        Gate::policy(Ticket::class, TicketPolicy::class);
        Gate::policy(CierreCaja::class, CierrePolicy::class);

        // BE-04: bypass total de `super_master` acotado al catálogo de
        // permisos (guard `api`). Devuelve `null` —nunca `false`— cuando la
        // ability no es un permiso, para no interferir con `UserPolicy` ni
        // con el scoping de `AlcanceService` (#85). No usa
        // `getAllPermissions()`: el bypass no depende de asignaciones.
        Gate::before(function (User $user, string $ability): ?bool {
            if (! $user->hasRole('super_master')) {
                return null;
            }

            $esPermisoDelCatalogo = app(PermissionRegistrar::class)
                ->getPermissions(['name' => $ability, 'guard_name' => 'api'])
                ->isNotEmpty();

            return $esPermisoDelCatalogo ? true : null;
        });

        // Límite dedicado de descarga del instalador (REQ-A3): 10/min por
        // usuario autenticado, o por IP para el serve firmado (sin auth).
        RateLimiter::for('releases-download', fn ($request) => Limit::perMinute(10)
            ->by($request->user()?->id ?: $request->ip()));

        // Feed público del auto-update (TQ-10, D1): 120/min por IP. Generoso:
        // el updater de cada taquilla reanuda descargas parciales (Range).
        RateLimiter::for('releases-feed', fn ($request) => Limit::perMinute(120)
            ->by($request->ip()));
    }
}
