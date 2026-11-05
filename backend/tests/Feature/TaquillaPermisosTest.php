<?php

namespace Tests\Feature;

use App\Models\Release;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Acceso efectivo de la taquilla tras BE-04 (WU-4).
 *
 * Preserva lo que el rol ya hacía (reportes, estadísticas, juegos, resultados,
 * lectura de su propia ficha, tickets y pagos) y fija los dos límites
 * deliberados: sin exchange rates y con releases (cambio BE-04).
 */
class TaquillaPermisosTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function demoUser(): User
    {
        // Taquilla DEMO pre-activada por el seeder (MAC registrada).
        return User::where('email', 'demo@lotto.com')->firstOrFail();
    }

    /**
     * @return array<string, string>
     */
    private function demoHeaders(): array
    {
        return [
            'X-Device-MAC' => '00:1A:2B:3C:4D:5E',
            'X-Device-Fingerprint' => 'demo-device-001',
        ];
    }

    private function comoTaquilla(string $metodo, string $uri, array $payload = []): TestResponse
    {
        return $this->withHeaders($this->demoHeaders())
            ->actingAs($this->demoUser(), 'sanctum')
            ->json($metodo, $uri, $payload);
    }

    public function test_taquilla_accede_a_los_reportes(): void
    {
        foreach ([
            '/api/v1/reportes/ventas-totales',
            '/api/v1/reportes/cuadre-caja',
            '/api/v1/reportes/relacion-tickets',
            '/api/v1/reportes/vencidos',
        ] as $uri) {
            $this->comoTaquilla('GET', $uri)->assertStatus(200);
        }
    }

    public function test_taquilla_accede_a_estadisticas(): void
    {
        $this->comoTaquilla('GET', '/api/v1/estadisticas/rendimiento')->assertStatus(200);
    }

    public function test_taquilla_accede_a_releases_latest_y_download(): void
    {
        Release::factory()->create(['published_at' => now()->subHour()]);

        $this->comoTaquilla('GET', '/api/v1/releases/latest')
            ->assertStatus(200)
            ->assertJsonStructure(['version', 'sha256', 'published_at']);

        $this->comoTaquilla('GET', '/api/v1/releases/download')
            ->assertStatus(200)
            ->assertJsonStructure(['url', 'expires_at']);
    }

    public function test_taquilla_recibe_403_en_exchange_rates(): void
    {
        $this->comoTaquilla('GET', '/api/v1/exchange-rates')->assertStatus(403);
    }

    public function test_taquilla_accede_a_juegos_y_resultados(): void
    {
        $this->comoTaquilla('GET', '/api/v1/juegos')->assertStatus(200);
        $this->comoTaquilla('GET', '/api/v1/resultados')->assertStatus(200);
    }

    public function test_taquilla_solo_ve_su_propia_ficha_en_users(): void
    {
        $response = $this->comoTaquilla('GET', '/api/v1/users');

        $response->assertStatus(200);

        $emails = collect($response->json())->pluck('email')->all();
        $this->assertSame(['demo@lotto.com'], $emails);
    }

    /**
     * Regresión de WU-3 referenciada aquí para cerrar la matriz: la agencia no
     * despierta tickets ni pagos (sin scoping de filas para ese rol).
     */
    public function test_agencia_sin_tickets_ni_pagos(): void
    {
        $agencia = User::where('email', 'agencia@lotto.com')->firstOrFail();

        $this->actingAs($agencia, 'sanctum')
            ->getJson('/api/v1/tickets')->assertStatus(403);

        $this->actingAs($agencia, 'sanctum')
            ->getJson('/api/v1/pagos/1')->assertStatus(403);

        $this->actingAs($agencia, 'sanctum')
            ->postJson('/api/v1/pagos', [])->assertStatus(403);
    }
}
