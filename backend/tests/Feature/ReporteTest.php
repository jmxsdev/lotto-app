<?php

namespace Tests\Feature;

use App\Models\Apuesta;
use App\Models\Banca;
use App\Models\ExchangeRate;
use App\Models\Grupo;
use App\Models\Juego;
use App\Models\Taquilla;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReporteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\DatabaseSeeder::class);
        $this->seed(\Database\Seeders\JuegoAnimalitosSeeder::class);
    }

    /**
     * 4.6 — test_ventas_totales_agrupa_por_banca:
     * Múltiples apuestas en distintas bancas deben agregarse correctamente.
     */
    public function test_ventas_totales_agrupa_por_banca()
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

        // Crear 2 bancas con sus jerarquías
        $banca1 = Banca::create(['name' => 'Banca Alpha', 'code' => 'BALPHA', 'active' => true]);
        $grupo1 = Grupo::create(['name' => 'Grupo Alpha', 'code' => 'GALPHA', 'banca_id' => $banca1->id, 'active' => true]);
        $taquilla1 = Taquilla::create(['name' => 'T-Alpha-1', 'code' => 'TA1', 'grupo_id' => $grupo1->id, 'active' => true]);

        $banca2 = Banca::create(['name' => 'Banca Beta', 'code' => 'BBETA', 'active' => true]);
        $grupo2 = Grupo::create(['name' => 'Grupo Beta', 'code' => 'GBETA', 'banca_id' => $banca2->id, 'active' => true]);
        $taquilla2 = Taquilla::create(['name' => 'T-Beta-1', 'code' => 'TB1', 'grupo_id' => $grupo2->id, 'active' => true]);

        // Apuestas en Banca Alpha
        for ($i = 0; $i < 3; $i++) {
            Apuesta::create([
                'taquilla_id' => $taquilla1->id,
                'juego_id' => $juego->id,
                'amount_bs' => 1000,
                'amount_usd' => 0,
                'exchange_rate_applied' => 36.50,
                'total_bs_equivalent' => 1000,
                'estado' => 'pendiente',
                'fecha_hora' => now()->subDays(1),
            ]);
        }

        // Apuestas en Banca Beta
        for ($i = 0; $i < 5; $i++) {
            Apuesta::create([
                'taquilla_id' => $taquilla2->id,
                'juego_id' => $juego->id,
                'amount_bs' => 500,
                'amount_usd' => 0,
                'exchange_rate_applied' => 36.50,
                'total_bs_equivalent' => 500,
                'estado' => 'pendiente',
                'fecha_hora' => now()->subDays(1),
            ]);
        }

        $response = $this->actingAs($super, 'sanctum')
            ->getJson('/api/v1/reportes/ventas-totales');

        $response->assertStatus(200);
        $data = $response->json('data');

        $this->assertCount(2, $data, 'Debe haber 2 filas, una por banca');

        // Buscar fila de Banca Alpha
        $alpha = collect($data)->firstWhere('Entidad', 'Banca Alpha');
        $this->assertNotNull($alpha, 'Banca Alpha debe estar presente');
        $this->assertEquals(3000, $alpha['Venta'], 'Venta de Banca Alpha debe ser 3000');
        $this->assertEquals(3, $alpha['Total'], 'Total de apuestas en Banca Alpha debe ser 3');

        // Buscar fila de Banca Beta
        $beta = collect($data)->firstWhere('Entidad', 'Banca Beta');
        $this->assertNotNull($beta, 'Banca Beta debe estar presente');
        $this->assertEquals(2500, $beta['Venta'], 'Venta de Banca Beta debe ser 2500');
        $this->assertEquals(5, $beta['Total'], 'Total de apuestas en Banca Beta debe ser 5');

        // Participación de cada banca
        $this->assertEquals(round((3000 / 5500) * 100, 2), $alpha['Participación']);
        $this->assertEquals(round((2500 / 5500) * 100, 2), $beta['Participación']);
    }

    /**
     * 4.7 — test_rango_vacio_retorna_200_no_404:
     * Un rango de fechas sin datos debe retornar HTTP 200 con arreglo vacío.
     */
    public function test_rango_vacio_retorna_200_no_404()
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

        $banca = Banca::create(['name' => 'Banca Empty', 'code' => 'BEMPTY', 'active' => true]);
        $grupo = Grupo::create(['name' => 'Grupo Empty', 'code' => 'GEMPTY', 'banca_id' => $banca->id, 'active' => true]);
        $taquilla = Taquilla::create(['name' => 'T-Empty', 'code' => 'TEMPTY', 'grupo_id' => $grupo->id, 'active' => true]);

        // Crear apuesta fuera del rango de consulta
        Apuesta::create([
            'taquilla_id' => $taquilla->id,
            'juego_id' => $juego->id,
            'amount_bs' => 1000,
            'amount_usd' => 0,
            'exchange_rate_applied' => 36.50,
            'total_bs_equivalent' => 1000,
            'estado' => 'pendiente',
            'fecha_hora' => '2025-01-15',
        ]);

        // Consultar un rango donde no hay apuestas
        $response = $this->actingAs($super, 'sanctum')
            ->getJson('/api/v1/reportes/ventas-totales?fecha_desde=2026-01-01&fecha_hasta=2026-01-31');

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertIsArray($data);
        $this->assertEmpty($data, 'El arreglo debe estar vacío para un rango sin datos');
    }

    /**
     * 4.8 — test_taquilla_solo_ve_sus_datos_en_reportes:
     * Un usuario con rol taquilla solo debe ver los datos de su propia taquilla.
     */
    public function test_taquilla_solo_ve_sus_datos_en_reportes()
    {
        $super = User::where('email', 'super@lotto.com')->first();

        ExchangeRate::create([
            'rate' => 36.50,
            'base_currency' => 'USD',
            'reference_date' => now(),
            'set_by' => $super->id,
            'is_active' => true,
        ]);

        $juego = Juego::where('slug', 'lotto-activo')->first();

        $banca = Banca::create(['name' => 'Banca Scope', 'code' => 'BSCOPE', 'active' => true]);
        $grupo = Grupo::create(['name' => 'Grupo Scope', 'code' => 'GSCOPE', 'banca_id' => $banca->id, 'active' => true]);
        $taquillaA = Taquilla::create(['name' => 'T-Alpha-Scope', 'code' => 'TAS', 'grupo_id' => $grupo->id, 'active' => true]);
        $taquillaB = Taquilla::create(['name' => 'T-Beta-Scope', 'code' => 'TBS', 'grupo_id' => $grupo->id, 'active' => true]);

        // Apuestas en taquilla A
        Apuesta::create([
            'taquilla_id' => $taquillaA->id,
            'juego_id' => $juego->id,
            'amount_bs' => 2000,
            'amount_usd' => 0,
            'exchange_rate_applied' => 36.50,
            'total_bs_equivalent' => 2000,
            'estado' => 'pendiente',
            'fecha_hora' => now()->subDay(),
        ]);

        // Apuestas en taquilla B
        Apuesta::create([
            'taquilla_id' => $taquillaB->id,
            'juego_id' => $juego->id,
            'amount_bs' => 5000,
            'amount_usd' => 0,
            'exchange_rate_applied' => 36.50,
            'total_bs_equivalent' => 5000,
            'estado' => 'pendiente',
            'fecha_hora' => now()->subDay(),
        ]);

        // Usuario de taquilla A — requiere MAC para pasar el middleware verify.mac
        $taquillaA->update(['mac_address' => 'AA:BB:CC:DD:EE:FF']);
        $taquillaUser = User::factory()->create([
            'taquilla_id' => $taquillaA->id,
            'role' => 'taquilla',
        ]);
        $taquillaUser->assignRole('taquilla');

        // Verificar en ventas-totales (el acceso jerárquico de taquilla solo ve sus apuestas)
        $response = $this->withHeaders(['X-Device-MAC' => 'AA:BB:CC:DD:EE:FF'])
            ->actingAs($taquillaUser, 'sanctum')
            ->getJson('/api/v1/reportes/ventas-totales');

        $response->assertStatus(200);
        $data = $response->json('data');

        // Como taquilla, debe ver datos de su propia banca (filtrando solo sus apuestas)
        $this->assertNotEmpty($data, 'Debe ver datos de su banca');
        // La taquilla A solo tiene apuestas por 2000
        $totalVenta = collect($data)->sum('Venta');
        $this->assertEquals(2000, $totalVenta, 'Solo debe ver 2000 en ventas (su propia taquilla)');

        // Verificar en rendimiento-taquillas
        $responseRend = $this->withHeaders(['X-Device-MAC' => 'AA:BB:CC:DD:EE:FF'])
            ->actingAs($taquillaUser, 'sanctum')
            ->getJson('/api/v1/reportes/rendimiento-taquillas');

        $responseRend->assertStatus(200);
        $rendData = $responseRend->json('data');

        $this->assertCount(1, $rendData, 'Solo debe ver 1 taquilla (la suya)');
        $this->assertEquals('T-Alpha-Scope', $rendData[0]['Agencia']);
        $this->assertEquals(2000, $rendData[0]['Venta']);
    }
}
