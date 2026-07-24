<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Juego;
use App\Models\Log;
use App\Models\Resultado;
use App\Jobs\FetchResultsJob;
use Illuminate\Http\Request;

class ResultadoController extends Controller
{
    public function index(Request $request)
    {
        $query = Resultado::with('juego');

        $fecha = $request->input('fecha', now()->format('Y-m-d'));
        $query->whereDate('fecha_sorteo', $fecha);

        if ($request->has('juego_id') && $request->juego_id) {
            $query->where('juego_id', $request->juego_id);
        }

        $resultados = $query->orderBy('hora_sorteo', 'asc')->paginate(50);
        $juegos = Juego::where('type', 'animalitos')->where('active', true)->get();
        
        $ultimoLog = Log::where('action', 'scrape_animalitos')
            ->orderBy('created_at', 'desc')
            ->first();

        return view('admin.resultados.index', compact('resultados', 'juegos', 'ultimoLog'));
    }

    public function scrape(Request $request)
    {
        $fecha = $request->input('fecha', now()->format('Y-m-d'));
        
        try {
            FetchResultsJob::dispatchSync($fecha);
            
            return redirect()->route('admin.resultados.index', ['fecha' => $fecha])
                ->with('success', 'Scraper ejecutado exitosamente para la fecha: ' . $fecha);
        } catch (\Exception $e) {
            return redirect()->route('admin.resultados.index', ['fecha' => $fecha])
                ->with('error', 'Error al ejecutar el scraper: ' . $e->getMessage());
        }
    }
}
