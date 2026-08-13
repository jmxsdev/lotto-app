<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Scraper Registry
    |--------------------------------------------------------------------------
    |
    | One scraper file per game, named {Page}{Game}Scraper. Adding a new page
    | or game requires only a scraper file plus an entry here — no job code
    | changes. Resolution matches by game slug (deterministic; the first
    | matching page entry wins).
    |
    */

    'registrations' => [

        'lottoactivo' => [
            'url_pattern' => 'lottoactivo.com',
            'default_multiplier' => 30,
            'retries' => 3,
            'backoff' => 300,
            'games' => [
                'animalitos' => [
                    'class' => \App\Plugins\Scrapers\LottoActivoAnimalitosScraper::class,
                    'page_slug' => 'animalitos',
                ],
                'trio-activo' => [
                    'class' => \App\Plugins\Scrapers\LottoActivoTrioActivoScraper::class,
                    'page_slug' => 'trio_activo',
                ],
                'terminal-activo' => [
                    'class' => \App\Plugins\Scrapers\LottoActivoTerminalActivoScraper::class,
                    'page_slug' => 'terminal_activo',
                ],
                'monje-millonario' => [
                    'class' => \App\Plugins\Scrapers\LottoActivoMonjeMillonarioScraper::class,
                    'page_slug' => 'animalitos',
                ],
                'lotto-activo-rd' => [
                    'class' => \App\Plugins\Scrapers\LottoActivoRDScraper::class,
                    'page_slug' => 'animalitos',
                ],
            ],
        ],

        'triplezulia' => [
            'url_pattern' => 'triplezulia.com',
            'default_multiplier' => 30,
            'retries' => 3,
            'backoff' => 300,
            'games' => [
                'triple-zulia' => [
                    'class' => \App\Plugins\Scrapers\TripleZuliaScraper::class,
                    'page_slug' => 'triplezulia',
                ],
            ],
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Retry defaults
    |--------------------------------------------------------------------------
    |
    | Fallback values used when a registration does not declare its own
    | retries/backoff.
    |
    */

    'retries' => 3,
    'backoff' => 300,

    /*
    |--------------------------------------------------------------------------
    | Telegram alerting
    |--------------------------------------------------------------------------
    |
    | Optional. When token or chat id is missing, terminal scrape failures
    | are recorded but no notification is sent.
    |
    */

    'telegram' => [
        'bot_token' => env('SCRAPER_TELEGRAM_BOT_TOKEN'),
        'chat_id' => env('SCRAPER_TELEGRAM_CHAT_ID'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Legacy resolution fallback
    |--------------------------------------------------------------------------
    |
    | While existing game rows migrate to the registry, resolution may fall
    | back to the legacy string-matching behavior. Disable after migration.
    |
    */

    'legacy_resolution' => env('SCRAPER_LEGACY_RESOLUTION', true),

];
