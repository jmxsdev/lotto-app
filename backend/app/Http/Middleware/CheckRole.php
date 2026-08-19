<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckRole
{
    public function handle(Request $request, Closure $next, ...$roles): Response
    {
        if (! $request->user()) {
            return response()->json(['message' => 'No autenticado.'], 401);
        }

        $userRoles = $request->user()->getRoleNames()->toArray();
        $hasRole = collect($roles)->intersect($userRoles)->isNotEmpty();

        if (! $hasRole) {
            return response()->json(['message' => 'No tienes permiso para acceder a este recurso.'], 403);
        }

        return $next($request);
    }
}
