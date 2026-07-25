<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Models\ExchangeRate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\DomCrawler\Crawler;
use GuzzleHttp\Client;

class ScrapeExchangeRateJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Log::info('=== INICIO ScrapeExchangeRateJob ===');

        try {
            // 1. Cliente HTTP
            $client = new Client([
                'timeout' => 30,
                'verify' => false,
                'headers' => [
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/114.0.0.0 Safari/537.36'
                ]
            ]);

            // 2. Obtener HTML del BCV
            $response = $client->get('https://www.bcv.org.ve/');
            $html = (string) $response->getBody();
            Log::info('HTML obtenido, longitud: ' . strlen($html));

            // 3. Parsear HTML
            $crawler = new Crawler($html);
            $rateText = null;

            // Buscar selector #dolar (el que funciona)
            $rateElement = $crawler->filter('#dolar');
            if ($rateElement->count()) {
                $rateText = $rateElement->text();
                Log::info('Texto encontrado en #dolar: ' . $rateText);
            } else {
                // Fallback: buscar por clase .centrado
                $rateElement = $crawler->filter('.centrado strong');
                if ($rateElement->count()) {
                    $rateText = $rateElement->text();
                    Log::info('Texto encontrado en .centrado strong: ' . $rateText);
                } else {
                    Log::error('No se encontró la tasa en la página.');
                    return;
                }
            }

            // 4. Limpiar y convertir a número
            $rateText = preg_replace('/[^0-9.,]/', '', $rateText);
            $rate = floatval(str_replace(',', '.', str_replace('.', '', $rateText)));
            Log::info('Tasa parseada: ' . $rate);

            // 5. Guardar la tasa como activa (desactiva la anterior)
            if ($rate > 0) {
                DB::beginTransaction();
                try {
                    ExchangeRate::where('is_active', true)->update(['is_active' => false]);

                    ExchangeRate::create([
                        'rate' => $rate,
                        'base_currency' => 'USD',
                        'reference_date' => now(),
                        'set_by' => null,
                        'notes' => 'Obtenida automáticamente de BCV',
                        'is_active' => true,
                    ]);

                    DB::commit();
                    Log::info('Tasa guardada y activada correctamente: ' . $rate);
                } catch (\Exception $e) {
                    DB::rollBack();
                    Log::error('Error al guardar la tasa: ' . $e->getMessage());
                }
            } else {
                Log::warning('La tasa obtenida no es válida: ' . $rateText);
            }

        } catch (\Exception $e) {
            Log::error('ERROR en ScrapeExchangeRateJob: ' . $e->getMessage() . ' en línea ' . $e->getLine());
            Log::error('Trace: ' . $e->getTraceAsString());
        }

        Log::info('=== FIN ScrapeExchangeRateJob ===');
    }
}
