<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Juego;
use App\Models\PluginJuego;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class JuegoController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:sanctum');
        $this->middleware('permission:manage_juegos')->except(['index', 'show']);
        $this->middleware('permission:view_juegos')->only(['index', 'show']);
    }

    public function index()
    {
        $juegos = Juego::with('pluginJuego')->get();
        return response()->json($juegos);
    }

    public function show(Juego $juego)
    {
        return response()->json($juego->load('pluginJuego'));
    }

    /**
     * Activar/Desactivar un juego
     */
    public function toggle(Request $request, Juego $juego)
    {
        $request->validate([
            'active' => 'required|boolean',
        ]);

        $juego->update(['active' => $request->active]);
        // También actualizar el plugin asociado
        if ($juego->pluginJuego) {
            $juego->pluginJuego->update(['active' => $request->active]);
        }

        return response()->json($juego->load('pluginJuego'));
    }

    /**
     * Registrar un nuevo juego con su plugin
     */
    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'slug' => 'required|string|unique:juegos,slug',
            'type' => 'required|string',
            'config' => 'nullable|array',
            'requires_scraper' => 'boolean',
            'scraper_url' => 'nullable|url',
            'plugin_class' => 'required|string|exists:App\\Plugins\\Juegos,class', // Validación básica
        ]);

        // Crear juego
        $juego = Juego::create([
            'name' => $request->name,
            'slug' => $request->slug,
            'type' => $request->type,
            'config' => $request->config,
            'requires_scraper' => $request->requires_scraper ?? false,
            'scraper_url' => $request->scraper_url,
            'active' => true,
        ]);

        // Crear plugin asociado
        PluginJuego::create([
            'juego_id' => $juego->id,
            'class_namespace' => $request->plugin_class,
            'version' => '1.0.0',
            'active' => true,
        ]);

        return response()->json($juego->load('pluginJuego'), 201);
    }
}
