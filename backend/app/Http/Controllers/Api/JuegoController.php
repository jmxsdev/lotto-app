<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Juego;
use App\Models\JuegoAuditoria;
use App\Models\PluginJuego;
use App\Services\JuegoPluginManager;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class JuegoController extends Controller
{
    public function index()
    {
        $juegos = Juego::with('pluginJuego')->get();
        return response()->json($juegos);
    }

    public function show(Juego $juego)
    {
        return response()->json($juego->load('pluginJuego', 'auditoria.user'));
    }

    public function toggle(Request $request, Juego $juego)
    {
        $request->validate([
            'active' => 'required|boolean',
        ]);

        $user = $request->user();
        $oldActive = $juego->active;
        $newActive = $request->active;

        $juego->update([
            'active' => $newActive,
            'updated_by' => $user->id,
        ]);

        if ($juego->pluginJuego) {
            $juego->pluginJuego->update([
                'active' => $newActive,
                'updated_by' => $user->id,
            ]);
        }

        JuegoAuditoria::create([
            'juego_id' => $juego->id,
            'user_id' => $user->id,
            'accion' => $newActive ? 'activar' : 'desactivar',
            'cambios' => [
                'before' => ['active' => $oldActive],
                'after' => ['active' => $newActive],
            ],
        ]);

        return response()->json($juego->load('pluginJuego', 'updatedByUser'));
    }

    public function update(Request $request, Juego $juego)
    {
        $user = $request->user();

        $request->validate([
            'name' => 'sometimes|string|max:255',
            'config' => 'nullable|array',
        ]);

        $changes = $request->only(['name', 'config']);

        if (empty($changes)) {
            return response()->json($juego->load('pluginJuego'));
        }

        $before = $juego->only(['name', 'config']);
        $juego->update(array_merge($changes, ['updated_by' => $user->id]));
        $after = $juego->only(['name', 'config']);

        JuegoAuditoria::create([
            'juego_id' => $juego->id,
            'user_id' => $user->id,
            'accion' => 'actualizar',
            'cambios' => [
                'before' => $before,
                'after' => $after,
            ],
        ]);

        return response()->json($juego->load('pluginJuego'));
    }

    public function opciones(Juego $juego)
    {
        $opciones = $juego->opciones()->get();

        if ($opciones->isEmpty()) {
            $plugin = app(JuegoPluginManager::class)->getPlugin($juego);
            if ($plugin) {
                return response()->json($plugin->obtenerOpciones());
            }
        }

        return response()->json($opciones);
    }

    public function horarios(Juego $juego)
    {
        return response()->json($juego->horarios()->get());
    }

    public function reglas(Juego $juego)
    {
        $plugin = app(JuegoPluginManager::class)->getPlugin($juego);

        if (!$plugin) {
            return response()->json(['message' => 'No hay plugin registrado para este juego.'], 404);
        }

        return response()->json($plugin->obtenerReglas());
    }
}
