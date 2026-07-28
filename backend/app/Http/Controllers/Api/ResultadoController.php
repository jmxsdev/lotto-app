<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ScrapeResultsJob;
use App\Models\Juego;
use App\Models\Resultado;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

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

    public function scrape(Request $request)
    {
        $juegoId = $request->input('juego_id');
        $fecha = $request->input('fecha', now()->format('Y-m-d'));

        $juegos = $juegoId
            ? Juego::where('id', $juegoId)->where('requires_scraper', true)->get()
            : Juego::where('requires_scraper', true)->get();

        if ($juegos->isEmpty()) {
            return response()->json([
                'message' => $juegoId
                    ? 'El juego especificado no existe o no requiere scraper'
                    : 'No hay juegos que requieran scraper',
            ], 404);
        }

        $resultados = [];

        foreach ($juegos as $juego) {
            try {
                $job = new ScrapeResultsJob($juego->id, $fecha);
                $job->handle();

                $resultados[$juego->name] = [
                    'status' => 'ok',
                ];
            } catch (\Exception $e) {
                Log::error("Error scraping {$juego->name}: " . $e->getMessage());
                $resultados[$juego->name] = [
                    'status' => 'error',
                    'error' => $e->getMessage(),
                ];
            }
        }

        return response()->json([
            'message' => 'Scrapers ejecutados',
            'fecha' => $fecha,
            'resultados' => $resultados,
        ]);
    }
}
