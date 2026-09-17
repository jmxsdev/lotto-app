<?php

namespace App\Console\Commands;

use App\Services\JuegoCatalogoService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class JuegosExportCommand extends Command
{
    protected $signature = 'juegos:export {--path= : Ruta de salida del catálogo (default: docs/juegos.json en la raíz del repo)}';

    protected $description = 'Exporta el catálogo de juegos a docs/juegos.json (contrato para el front/taquilla)';

    public function handle(JuegoCatalogoService $catalogo): int
    {
        $path = $this->option('path') ?: base_path('../docs/juegos.json');

        $json = json_encode(
            $catalogo->generar(),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
        );

        if ($json === false) {
            $this->error('No se pudo generar el JSON del catálogo.');

            return self::FAILURE;
        }

        File::ensureDirectoryExists(dirname($path));
        File::put($path, $json."\n");

        $this->info("Catálogo de juegos exportado a {$path}");

        return self::SUCCESS;
    }
}
