<?php

namespace Tests\Feature;

use App\Models\Banca;
use App\Models\Juego;
use App\Models\JuegoLimite;
use App\Services\JuegoLimiteService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Cobertura directa de JuegoLimiteService (8 callers, sin tests propios).
 *
 * WU1 — los campos dormidos (fraccion/limite_tiempo) salen de CAMPOS:
 *  - un ítem con dormidos + campo vigente persiste SOLO el campo vigente;
 *  - un ítem SOLO con dormidos queda sin campos configurables → 422 (guard
 *    present-fields-only retenido, spec R1);
 *  - la semántica null→elimina-fila se conserva intacta.
 */
class JuegoLimiteServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function servicio(): JuegoLimiteService
    {
        return new JuegoLimiteService;
    }

    private function bancaSeeded(): Banca
    {
        return Banca::where('code', 'BT001')->first();
    }

    private function juegoNuevo(): Juego
    {
        return Juego::create([
            'name' => 'Juego Service Test',
            'slug' => 'juego-service-'.uniqid(),
            'type' => 'animalitos',
            'active' => true,
        ]);
    }

    private function claveBanca(Juego $juego, Banca $banca): array
    {
        return [
            'juego_id' => $juego->id,
            'banca_id' => $banca->id,
            'grupo_id' => null,
            'taquilla_id' => null,
            'moneda' => 'bs',
        ];
    }

    public function test_aplicar_item_ignora_campos_dormidos()
    {
        $banca = $this->bancaSeeded();
        $juego = $this->juegoNuevo();
        $resultados = [];

        $this->servicio()->aplicarItemLimite([
            'juego_id' => $juego->id,
            'banca_id' => $banca->id,
            'moneda' => 'bs',
            'limite_minimo' => 100,
            'fraccion' => true,
            'limite_tiempo' => 30,
        ], null, $resultados);

        $this->assertCount(1, $resultados);

        $limite = JuegoLimite::where($this->claveBanca($juego, $banca))->first();

        $this->assertNotNull($limite);
        $this->assertSame(100.0, (float) $limite->limite_minimo);
        // Los dormidos no se persisten: fraccion queda en su default y limite_tiempo nulo
        $this->assertFalse((bool) $limite->fraccion);
        $this->assertNull($limite->limite_tiempo);
    }

    public function test_aplicar_item_solo_campos_dormidos_lanza_422()
    {
        $banca = $this->bancaSeeded();
        $juego = $this->juegoNuevo();
        $resultados = [];

        try {
            $this->servicio()->aplicarItemLimite([
                'juego_id' => $juego->id,
                'banca_id' => $banca->id,
                'moneda' => 'bs',
                'fraccion' => true,
                'limite_tiempo' => 30,
            ], null, $resultados);
            $this->fail('Un ítem solo con campos dormidos debe rechazarse con 422.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }

        $this->assertDatabaseMissing('juego_limites', [
            'juego_id' => $juego->id, 'banca_id' => $banca->id, 'moneda' => 'bs',
        ]);
    }

    public function test_aplicar_item_sin_campos_lanza_422()
    {
        $banca = $this->bancaSeeded();
        $juego = $this->juegoNuevo();
        $resultados = [];

        try {
            $this->servicio()->aplicarItemLimite([
                'juego_id' => $juego->id,
                'banca_id' => $banca->id,
                'moneda' => 'bs',
            ], null, $resultados);
            $this->fail('Un ítem sin campos configurables debe rechazarse con 422.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
    }

    public function test_aplicar_item_null_elimina_la_fila()
    {
        $banca = $this->bancaSeeded();
        $juego = $this->juegoNuevo();

        // Fila previa (juego sin filas sembradas)
        JuegoLimite::create(array_merge($this->claveBanca($juego, $banca), ['limite_minimo' => 100]));
        $this->assertDatabaseHas('juego_limites', $this->claveBanca($juego, $banca));

        $resultados = [];
        $this->servicio()->aplicarItemLimite([
            'juego_id' => $juego->id,
            'banca_id' => $banca->id,
            'moneda' => 'bs',
            'limite_minimo' => null,
        ], null, $resultados);

        // Semántica present-fields-only intacta: null explícito → elimina la fila
        $this->assertDatabaseMissing('juego_limites', $this->claveBanca($juego, $banca));
    }
}
