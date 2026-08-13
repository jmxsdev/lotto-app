<?php

namespace App\Plugins\Scrapers;

use App\Models\Juego;
use Illuminate\Support\Str;

/**
 * Central scraper registry. Resolution is data-driven from
 * config/scrapers.php and never string-matches inside jobs.
 */
final class ScraperRegistry
{
    /**
     * Resolve a game to its registered scraper entry.
     *
     * Deterministic rules:
     * - A game is matched by its slug inside a page registration.
     * - When the same game slug appears in more than one page, the first
     *   registration wins.
     * - An unresolvable game returns null (or a legacy entry when the
     *   migration fallback toggle is enabled).
     */
    public static function resolve(Juego $juego): ?ScraperEntry
    {
        foreach ((array) config('scrapers.registrations', []) as $pageKey => $page) {
            foreach ((array) ($page['games'] ?? []) as $gameSlug => $game) {
                if ($juego->slug !== $gameSlug || ! self::urlMatches($juego, $page)) {
                    continue;
                }

                return new ScraperEntry(
                    key: $pageKey,
                    class: $game['class'],
                    pageSlug: $game['page_slug'] ?? $gameSlug,
                    gameKey: $gameSlug,
                    defaultMultiplier: (int) ($page['default_multiplier'] ?? config('scrapers.default_multiplier', 30)),
                    retries: (int) ($page['retries'] ?? config('scrapers.retries', 3)),
                    backoff: (int) ($page['backoff'] ?? config('scrapers.backoff', 300)),
                );
            }
        }

        return config('scrapers.legacy_resolution', false)
            ? self::resolveLegacy($juego)
            : null;
    }

    /**
     * Instantiate the scraper class registered for an entry.
     */
    public static function make(ScraperEntry $entry): BaseScraper
    {
        $class = $entry->class;

        return new $class();
    }

    /**
     * Optional entry-level URL gate. When a page declares no url_pattern,
     * the slug match alone authorizes the entry (rows may lack scraper_url).
     */
    private static function urlMatches(Juego $juego, array $page): bool
    {
        $pattern = $page['url_pattern'] ?? null;

        if (! $pattern) {
            return true;
        }

        $url = $juego->scraper_url ?? '';

        return $url === '' || str_contains($url, $pattern);
    }

    /**
     * Legacy resolution, kept only behind the migration fallback toggle.
     */
    private static function resolveLegacy(Juego $juego): ?ScraperEntry
    {
        $url = $juego->scraper_url ?? '';
        $type = $juego->type;

        if (str_contains($url, 'lottoactivo.com')) {
            return new ScraperEntry('legacy', LottoActivoAnimalitosScraper::class, 'animalitos', $juego->slug, 30, 3, 300);
        }

        if (str_contains($url, 'triplezulia')) {
            return new ScraperEntry('legacy', TripleZuliaScraper::class, 'triplezulia', $juego->slug, 30, 3, 300);
        }

        $class = 'App\\Plugins\\Scrapers\\'.Str::studly($type).'Scraper';

        return class_exists($class)
            ? new ScraperEntry('legacy', $class, $juego->slug, $juego->slug, 30, 3, 300)
            : null;
    }
}
