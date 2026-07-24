<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Resultado;
use Illuminate\Http\Request;

class ResultadoController extends Controller
{
    public function index(Request $request)
    {
        $query = Resultado::with('juego');

        if ($request->has('fecha')) {
            $query->whereDate('fecha_sorteo', $request->fecha);
        } else {
            $query->whereDate('fecha_sorteo', now()->format('Y-m-d'));
        }

        if ($request->has('juego_id')) {
            $query->where('juego_id', $request->juego_id);
        }

        if ($request->has('hora')) {
            $query->where('hora_sorteo', $request->hora);
        }

        $resultados = $query->orderBy('fecha_sorteo', 'desc')
            ->orderBy('hora_sorteo', 'asc')
            ->paginate($request->input('per_page', 50));

        return response()->json($resultados);
    }

    public function show(Resultado $resultado)
    {
        return response()->json($resultado->load('juego'));
    }
}
