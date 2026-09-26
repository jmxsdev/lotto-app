<?php

namespace App\Services;

use App\Models\Juego;
use App\Plugins\Scrapers\AnimalitosScraper;
use App\Plugins\Scrapers\TripletasScraper;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Resuelve la fuente de scrape de cada juego y consolida los juegos que
 * comparten feed en un único job por fuente.
 *
 * Reglas de consolidación:
 * - Feed `animalitos` de lottoactivo.com (Lotto Activo, RD Internacional,
 *   RD República Dominicana, Monje Millonario) → fuente `lottoactivo-animalitos`.
 * - Trio Activo / Terminal Activo → feed propio (`trio_activo`/`terminal_activo`).
 * - Resto de juegos → 1 fuente por juego (key = slug del juego).
 *
 * La resolución de clase replica el contrato anterior de ScrapeResultsJob:
 * `scraper_class` explícito > URL lottoactivo > URL triplezulia > convención
 * por type (`Str::studly(type).'Scraper'`).
 */
class ScraperSourceResolver
{
    public const KEY_ANIMALITOS = 'lottoactivo-animalitos';

    public const KEY_TRIO_ACTIVO = 'lottoactivo-trio_activo';

    public const KEY_TERMINAL_ACTIVO = 'lottoactivo-terminal_activo';

    /**
     * Todas las fuentes con sus miembros, agrupadas por feed.
     *
     * @return ScraperSource[]
     */
    public function sources(): array
    {
        $juegos = Juego::where('requires_scraper', true)->get();
        $porClave = [];

        foreach ($juegos as $juego) {
            $fuente = $this->buildSource($juego);

            $porClave[$fuente->key]['scraperClass'] = $fuente->scraperClass;
            $porClave[$fuente->key]['slug'] = $fuente->slug;
            $porClave[$fuente->key]['juegoIds'][] = $juego->id;
        }

        return collect($porClave)
            ->map(fn (array $datos, string $key) => new ScraperSource(
                $key,
                $datos['scraperClass'],
                $datos['slug'],
                $datos['juegoIds'],
            ))
            ->values()
            ->all();
    }

    /**
     * La fuente a la que pertenece un juego (con todos sus miembros).
     */
    public function sourceOf(Juego $juego): ?ScraperSource
    {
        if (! $juego->requires_scraper) {
            return null;
        }

        $fuente = $this->buildSource($juego);

        $juegoIds = Juego::where('requires_scraper', true)
            ->get()
            ->filter(fn (Juego $j) => $this->fuenteKeyDe($j) === $fuente->key)
            ->pluck('id')
            ->sort()
            ->values()
            ->all();

        return new ScraperSource($fuente->key, $fuente->scraperClass, $fuente->slug, $juegoIds);
    }

    /**
     * La fuente registrada bajo una clave (usada por ScrapeSourceJob).
     */
    public function sourceByKey(string $key): ?ScraperSource
    {
        return collect($this->sources())
            ->first(fn (ScraperSource $fuente) => $fuente->key === $key);
    }

    /**
     * Instancia el scraper de la fuente de un juego (o null si no hay scraper).
     */
    public function scraperFor(Juego $juego): ?object
    {
        $fuente = $this->sourceOf($juego);

        if (! $fuente || ! $fuente->scraperClass) {
            return null;
        }

        return $this->instantiateClass($fuente->scraperClass, $juego);
    }

    /**
     * Resuelve la clase de scraper de un juego (contrato ScrapeResultsJob).
     */
    public function scraperClassFor(Juego $juego): ?string
    {
        if ($juego->scraper_class) {
            $clase = $juego->scraper_class;

            if (class_exists($clase)) {
                return $clase;
            }

            Log::warning("ScraperSourceResolver: scraper_class {$juego->scraper_class} no existe para {$juego->name}");

            return null;
        }

        $url = $juego->scraper_url ?? '';
        $type = $juego->type;

        if (str_contains($url, 'lottoactivo.com')) {
            return AnimalitosScraper::class;
        }
        if (str_contains($url, 'triplezulia')) {
            return TripletasScraper::class;
        }

        // Fallback: convención por type
        $clase = 'App\\Plugins\\Scrapers\\'.Str::studly($type).'Scraper';

        return class_exists($clase) ? $clase : null;
    }

    /**
     * Instancia una clase de scraper (vía contenedor, mockeable en tests).
     */
    public function instantiateClass(string $clase, Juego $juego): object
    {
        if ($clase === AnimalitosScraper::class) {
            return app()->make(AnimalitosScraper::class, ['slug' => $this->slugLottoactivo($juego)]);
        }

        return app()->makeWith($clase, ['juego' => $juego]);
    }

    protected function buildSource(Juego $juego): ScraperSource
    {
        $clase = $this->scraperClassFor($juego);
        $slug = null;
        $key = $juego->slug;

        if ($clase === AnimalitosScraper::class) {
            $slug = $this->slugLottoactivo($juego);
            $key = 'lottoactivo-'.$slug;
        }

        return new ScraperSource($key, $clase, $slug, []);
    }

    protected function fuenteKeyDe(Juego $juego): string
    {
        return $this->buildSource($juego)->key;
    }

    protected function slugLottoactivo(Juego $juego): string
    {
        $url = $juego->scraper_url ?? '';

        if (str_contains($url, 'trio_activo')) {
            return 'trio_activo';
        }
        if (str_contains($url, 'terminal_activo')) {
            return 'terminal_activo';
        }

        return 'animalitos';
    }
}
