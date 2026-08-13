<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Juego;
use App\Models\Log;
use App\Models\Resultado;
use App\Services\ScrapeRunner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log as FacadeLog;

class ResultadoController extends Controller
{
    public function __construct(
        private ScrapeRunner $scrapeRunner,
    ) {}

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

        $ultimoLog = Log::where('action', 'scrape_resultados')
            ->orderBy('created_at', 'desc')
            ->first();

        return view('admin.resultados.index', compact('resultados', 'juegos', 'ultimoLog'));
    }

    public function scrape(Request $request)
    {
        $fecha = $request->input('fecha', now()->format('Y-m-d'));

        $juegos = Juego::where('requires_scraper', true)
            ->where('active', true)
            ->get();

        $errores = [];

        foreach ($juegos as $juego) {
            try {
                $this->scrapeRunner->run($juego, $fecha);
            } catch (\Throwable $e) {
                $errores[] = "{$juego->name}: ".$e->getMessage();
                FacadeLog::error("Admin scrape {$juego->name}: ".$e->getMessage());
            }
        }

        if (! empty($errores)) {
            return redirect()->route('admin.resultados.index', ['fecha' => $fecha])
                ->with('error', 'Errores al ejecutar scrapers: '.implode(' | ', $errores));
        }

        return redirect()->route('admin.resultados.index', ['fecha' => $fecha])
            ->with('success', 'Scrapers ejecutados exitosamente para la fecha: '.$fecha);
    }
}
