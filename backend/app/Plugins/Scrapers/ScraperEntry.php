<?php

namespace App\Plugins\Scrapers;

/**
 * A single registry entry: one game resolved to its scraper class and
 * business configuration. Built by ScraperRegistry::resolve().
 */
final class ScraperEntry
{
    public function __construct(
        public readonly string $key,
        public readonly string $class,
        public readonly string $pageSlug,
        public readonly string $gameKey,
        public readonly int $defaultMultiplier,
        public readonly int $retries,
        public readonly int $backoff,
    ) {}
}
