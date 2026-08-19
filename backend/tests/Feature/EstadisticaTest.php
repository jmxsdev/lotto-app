<?php

namespace Tests\Feature;

use App\Models\Apuesta;
use App\Models\Banca;
use App\Models\ExchangeRate;
use App\Models\Grupo;
use App\Models\Juego;
use App\Models\Taquilla;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EstadisticaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\DatabaseSeeder::class);
        $this->seed(\Database\Seeders\JuegoAnimalitosSeeder::class);
    }

    /**
     * 5.6 — test_time_series_zero_fills_gaps:
     * Apuestas en días 1, 3, 5 de un rango de 5 días deben producir
     * 5 buckets diarios con ceros en los días 2 y 4.
     */
    public function test_time_series_zero_fills_gaps()
    {
        $super = User::where('email', 'super@lotto.com')->first();
        $super->assignRole('super_master');

        ExchangeRate::create([
            'rate' => 36.50,
            'base_currency' => 'USD',
            'reference_date' => now(),
            'set_by' => $super->id,
            'is_active' => true,
        ]);

        $juego = Juego::where('slug', 'lotto-activo')->first();

        $banca = Banca::create(['name' => 'Banca Charts', 'code' => 'BCHART', 'active' => true]);
        $grupo = Grupo::create(['name' => 'Grupo Charts', 'code' => 'GCHART', 'banca_id' => $banca->id, 'active' => true]);
        $taquilla = Taquilla::create(['name' => 'T-Charts', 'code' => 'TCHART', 'grupo_id' => $grupo->id, 'active' => true]);

        // Apuestas en días intercalados: 1, 3, 5
        $diasConDatos = ['2026-08-01', '2026-08-03', '2026-08-05'];
        foreach ($diasConDatos as $fecha) {
            Apuesta::create([
                'taquilla_id' => $taquilla->id,
                'juego_id' => $juego->id,
                'amount_bs' => 1000,
                'amount_usd' => 0,
                'exchange_rate_applied' => 36.50,
                'total_bs_equivalent' => 1000,
                'estado' => 'pendiente',
                'fecha_hora' => $fecha . ' 10:00:00',
            ]);
        }

        $response = $this->actingAs($super, 'sanctum')
            ->getJson('/api/v1/estadisticas/rendimiento?fecha_desde=2026-08-01&fecha_hasta=2026-08-05');

        $response->assertStatus(200);
        $json = $response->json();

        // Verificar estructura
        $this->assertArrayHasKey('labels', $json);
        $this->assertArrayHasKey('series', $json);
        $this->assertArrayHasKey('ventas', $json['series']);
        $this->assertArrayHasKey('premios', $json['series']);
        $this->assertArrayHasKey('pagados', $json['series']);
        $this->assertArrayHasKey('vencidos', $json['series']);
        $this->assertArrayHasKey('devolucion', $json['series']);
        $this->assertArrayHasKey('saldo', $json['series']);

        // Deben ser 5 buckets (días 1 al 5)
        $this->assertCount(5, $json['labels']);
        $this->assertEquals('2026-08-01', $json['labels'][0]);
        $this->assertEquals('2026-08-05', $json['labels'][4]);

        // Los 6 series deben tener 5 elementos cada una
        foreach (['ventas', 'premios', 'pagados', 'vencidos', 'devolucion', 'saldo'] as $key) {
            $this->assertCount(5, $json['series'][$key], "Serie '$key' debe tener 5 elementos");
        }

        // Día 1 debe tener ventas = 1000 (índice 0)
        $this->assertEquals(1000, $json['series']['ventas'][0], 'Día 1 debe tener 1000 en ventas');
        $this->assertEquals(1000, $json['series']['saldo'][0], 'Día 1 debe tener 1000 en saldo');

        // Día 2 debe tener ceros (índice 1)
        $this->assertEquals(0, $json['series']['ventas'][1], 'Día 2 debe tener 0 en ventas (gap)');
        $this->assertEquals(0, $json['series']['saldo'][1], 'Día 2 debe tener 0 en saldo (gap)');

        // Día 3 debe tener ventas = 1000 (índice 2)
        $this->assertEquals(1000, $json['series']['ventas'][2], 'Día 3 debe tener 1000 en ventas');

        // Día 4 debe tener ceros (índice 3)
        $this->assertEquals(0, $json['series']['ventas'][3], 'Día 4 debe tener 0 en ventas (gap)');

        // Día 5 debe tener ventas = 1000 (índice 4)
        $this->assertEquals(1000, $json['series']['ventas'][4], 'Día 5 debe tener 1000 en ventas');
    }

    /**
     * 5.7 — test_rango_vacio_rendimiento_retorna_200:
     * Un rango de fechas sin datos debe retornar HTTP 200 con series vacías o en cero.
     */
    public function test_rango_vacio_rendimiento_retorna_200()
    {
        $super = User::where('email', 'super@lotto.com')->first();
        $super->assignRole('super_master');

        ExchangeRate::create([
            'rate' => 36.50,
            'base_currency' => 'USD',
            'reference_date' => now(),
            'set_by' => $super->id,
            'is_active' => true,
        ]);

        $juego = Juego::where('slug', 'lotto-activo')->first();

        $banca = Banca::create(['name' => 'Banca Empty', 'code' => 'BEMPT2', 'active' => true]);
        $grupo = Grupo::create(['name' => 'Grupo Empty', 'code' => 'GEMPT2', 'banca_id' => $banca->id, 'active' => true]);
        $taquilla = Taquilla::create(['name' => 'T-Empty', 'code' => 'TEMPT2', 'grupo_id' => $grupo->id, 'active' => true]);

        // Crear apuesta fuera del rango de consulta
        Apuesta::create([
            'taquilla_id' => $taquilla->id,
            'juego_id' => $juego->id,
            'amount_bs' => 1000,
            'amount_usd' => 0,
            'exchange_rate_applied' => 36.50,
            'total_bs_equivalent' => 1000,
            'estado' => 'pendiente',
            'fecha_hora' => '2025-01-15 10:00:00',
        ]);

        // Consultar un rango donde no hay apuestas
        $response = $this->actingAs($super, 'sanctum')
            ->getJson('/api/v1/estadisticas/rendimiento?fecha_desde=2026-01-01&fecha_hasta=2026-01-31');

        $response->assertStatus(200);
        $json = $response->json();

        // Debe tener estructura correcta
        $this->assertArrayHasKey('labels', $json);
        $this->assertArrayHasKey('series', $json);

        // Deben haber 31 labels (enero tiene 31 días)
        $this->assertCount(31, $json['labels']);

        // Todos los valores de todas las series deben ser 0
        foreach (['ventas', 'premios', 'pagados', 'vencidos', 'devolucion', 'saldo'] as $key) {
            $this->assertCount(31, $json['series'][$key], "Serie '$key' debe tener 31 elementos");
            $suma = array_sum($json['series'][$key]);
            $this->assertEquals(0, $suma, "Serie '$key' debe sumar 0 para rango vacío");
        }
    }
}
