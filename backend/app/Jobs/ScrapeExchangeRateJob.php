<?php

namespace App\Jobs;

use App\Models\ExchangeRate;
use GuzzleHttp\Client;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\DomCrawler\Crawler;

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
            // 1-2. Obtener HTML del BCV (fetch separado para testear sin red)
            $html = $this->fetchHtml();
            Log::info('HTML obtenido, longitud: '.strlen($html));

            // 3. Parsear HTML
            $crawler = new Crawler($html);
            $rateText = null;

            // Buscar selector #dolar (el que funciona)
            $rateElement = $crawler->filter('#dolar');
            if ($rateElement->count()) {
                $rateText = $rateElement->text();
                Log::info('Texto encontrado en #dolar: '.$rateText);
            } else {
                // Fallback: buscar por clase .centrado
                $rateElement = $crawler->filter('.centrado strong');
                if ($rateElement->count()) {
                    $rateText = $rateElement->text();
                    Log::info('Texto encontrado en .centrado strong: '.$rateText);
                } else {
                    Log::error('No se encontró la tasa en la página.');

                    return;
                }
            }

            // 4. Limpiar y convertir a número
            $rateText = preg_replace('/[^0-9.,]/', '', $rateText);
            $rate = floatval(str_replace(',', '.', str_replace('.', '', $rateText)));
            Log::info('Tasa parseada: '.$rate);

            // 5. Guarda de idempotencia: si la tasa parseada iguala la activa,
            // NO se inserta fila nueva (el BCV publica una tasa por día hábil;
            // insertar cada hora llenaría la tabla de duplicados). La columna es
            // decimal(10,4), así que se compara redondeando a 4 decimales (el
            // float crudo de 8 decimales daría falsos "distintos"). Sin tasa
            // activa previa → inserta.
            if ($rate > 0) {
                $activa = ExchangeRate::where('is_active', true)->latest('reference_date')->first();

                if ($activa !== null && round($rate, 4) === round((float) $activa->rate, 4)) {
                    Log::info('Tasa sin cambios ('.$rate.'): se omite el INSERT; la fila activa se conserva.');

                    return;
                }

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
                    Log::info('Tasa guardada y activada correctamente: '.$rate);
                } catch (\Exception $e) {
                    DB::rollBack();
                    Log::error('Error al guardar la tasa: '.$e->getMessage());
                }
            } else {
                Log::warning('La tasa obtenida no es válida: '.$rateText);
            }

        } catch (\Exception $e) {
            Log::error('ERROR en ScrapeExchangeRateJob: '.$e->getMessage().' en línea '.$e->getLine());
            Log::error('Trace: '.$e->getTraceAsString());
        }

        Log::info('=== FIN ScrapeExchangeRateJob ===');
    }

    /**
     * Fetch del HTML del BCV. Aislado en su propio método para que los tests
     * puedan sobreescribirlo con un doble (fixtures) sin red, manteniendo el
     * job encolable (un Client por constructor no serializa en la cola).
     */
    protected function fetchHtml(): string
    {
        $client = new Client([
            'timeout' => 30,
            'verify' => false,
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/114.0.0.0 Safari/537.36',
            ],
        ]);

        $response = $client->get('https://www.bcv.org.ve/');

        return (string) $response->getBody();
    }
}
