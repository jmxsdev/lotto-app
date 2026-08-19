<?php

namespace App\Console\Commands;

use App\Jobs\ScrapeExchangeRateJob;
use Illuminate\Console\Command;

class ScrapeExchangeRate extends Command
{
    protected $signature = 'scrape:exchange-rate';

    protected $description = 'Obtiene la tasa de cambio del BCV mediante scraping';

    public function handle()
    {
        $this->info('Iniciando scraping de tasa BCV...');

        // Ejecución síncrona (sin colas)
        $job = new ScrapeExchangeRateJob;
        $job->handle();

        $this->info('Scraping completado. Revisa el log.');

        return 0;
    }
}
