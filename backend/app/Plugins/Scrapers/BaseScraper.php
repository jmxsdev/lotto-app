<?php

namespace App\Plugins\Scrapers;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;
use Symfony\Component\DomCrawler\Crawler;

abstract class BaseScraper
{
    protected Client $client;
    protected string $baseUrl;
    protected string $scraperName;

    public function __construct()
    {
        $this->client = new Client([
            'timeout' => 30,
            'verify' => false,
            'cookies' => true,
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
                'Accept-Language' => 'es-ES,es;q=0.9,en;q=0.8',
                'Accept-Encoding' => 'gzip, deflate, br',
                'Connection' => 'keep-alive',
            ]
        ]);
    }

    abstract protected function fetch(string $fecha): string;

    abstract protected function parse(string $rawData): array;

    public function execute(string $fecha = null): array
    {
        if (!$fecha) {
            $fecha = now()->format('Y-m-d');
        }

        $this->logInfo("Iniciando scrape para fecha: {$fecha}");

        try {
            $rawData = $this->fetch($fecha);
            $resultados = $this->parse($rawData);
            
            $this->logInfo("Scrape completado. Resultados obtenidos: " . count($resultados));
            
            return $resultados;
        } catch (\Exception $e) {
            $this->logError("Error en scrape: " . $e->getMessage(), [
                'exception' => get_class($e),
                'line' => $e->getLine(),
                'file' => $e->getFile(),
            ]);
            throw $e;
        }
    }

    protected function getHtml(string $url, array $options = []): string
    {
        try {
            $response = $this->client->get($url, $options);
            $html = (string) $response->getBody();
            
            $this->logInfo("HTML obtenido de {$url}, longitud: " . strlen($html));
            
            return $html;
        } catch (GuzzleException $e) {
            $this->logError("Error HTTP GET en {$url}: " . $e->getMessage());
            throw $e;
        }
    }

    protected function postJson(string $url, array $data): string
    {
        try {
            $response = $this->client->post($url, [
                'form_params' => $data,
                'headers' => [
                    'X-Requested-With' => 'XMLHttpRequest',
                    'Accept' => 'application/json',
                ]
            ]);
            
            $body = (string) $response->getBody();
            $this->logInfo("POST a {$url} exitoso, respuesta longitud: " . strlen($body));
            
            return $body;
        } catch (GuzzleException $e) {
            $this->logError("Error HTTP POST en {$url}: " . $e->getMessage());
            throw $e;
        }
    }

    protected function postJsonPayload(string $url, array $data): string
    {
        try {
            $response = $this->client->post($url, [
                'json' => $data,
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ]
            ]);
            
            $body = (string) $response->getBody();
            $this->logInfo("POST JSON a {$url} exitoso, respuesta longitud: " . strlen($body));
            
            return $body;
        } catch (GuzzleException $e) {
            $this->logError("Error HTTP POST JSON en {$url}: " . $e->getMessage());
            throw $e;
        }
    }

    protected function createCrawler(string $html): Crawler
    {
        return new Crawler($html);
    }

    protected function logInfo(string $message, array $context = []): void
    {
        Log::info("[{$this->scraperName}] {$message}", $context);
    }

    protected function logError(string $message, array $context = []): void
    {
        Log::error("[{$this->scraperName}] {$message}", $context);
    }

    protected function logWarning(string $message, array $context = []): void
    {
        Log::warning("[{$this->scraperName}] {$message}", $context);
    }
}
