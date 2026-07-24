<?php

namespace Tests\Unit;

use App\Models\Apuesta;
use App\Models\ExchangeRate;
use App\Models\Juego;
use App\Models\User;
use App\Services\ApuestaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApuestaServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
        $this->seed(\Database\Seeders\JuegoAnimalitosSeeder::class);
    }

    public function test_calcula_total_bs_equivalent_correctamente()
    {
        $user = User::where('email', 'super@lotto.com')->first();
        
        ExchangeRate::create([
            'rate' => 36.50,
            'base_currency' => 'USD',
            'reference_date' => now(),
            'set_by' => $user->id,
            'is_active' => true,
        ]);

        $service = new ApuestaService();
        $total = $service->calcularTotal(1800, 50);
        
        $this->assertEquals(3625.00, $total);
    }

    public function test_calcula_total_solo_bs()
    {
        $user = User::where('email', 'super@lotto.com')->first();
        
        ExchangeRate::create([
            'rate' => 36.50,
            'base_currency' => 'USD',
            'reference_date' => now(),
            'set_by' => $user->id,
            'is_active' => true,
        ]);

        $service = new ApuestaService();
        $total = $service->calcularTotal(3600, 0);
        
        $this->assertEquals(3600.00, $total);
    }

    public function test_calcula_total_solo_usd()
    {
        $user = User::where('email', 'super@lotto.com')->first();
        
        ExchangeRate::create([
            'rate' => 36.50,
            'base_currency' => 'USD',
            'reference_date' => now(),
            'set_by' => $user->id,
            'is_active' => true,
        ]);

        $service = new ApuestaService();
        $total = $service->calcularTotal(0, 100);
        
        $this->assertEquals(3650.00, $total);
    }

    public function test_valida_costo_minimo_pasa()
    {
        $juego = Juego::where('slug', 'animalitos')->first();
        
        if (!$juego) {
            $this->markTestSkipped('Juego Animalitos no existe');
        }

        // Actualizar config del juego con precio custom
        $juego->update(['config' => json_encode(['precio' => 3600])]);

        $service = new ApuestaService();
        
        // Debe pasar (3625 >= 3600)
        $resultado = $service->validarCostoMinimo(3625, $juego->id);
        $this->assertTrue($resultado['valid']);
        $this->assertEquals(3600, $resultado['costo_minimo']);
    }

    public function test_valida_costo_minimo_falla()
    {
        $juego = Juego::where('slug', 'animalitos')->first();
        
        if (!$juego) {
            $this->markTestSkipped('Juego Animalitos no existe');
        }

        $juego->update(['config' => json_encode(['precio' => 3600])]);

        $service = new ApuestaService();
        
        // Debe fallar (3500 < 3600)
        $resultado = $service->validarCostoMinimo(3500, $juego->id);
        $this->assertFalse($resultado['valid']);
        $this->assertArrayHasKey('required_min', $resultado);
        $this->assertEquals(3600, $resultado['required_min']);
    }

    public function test_convierte_bs_a_usd()
    {
        $user = User::where('email', 'super@lotto.com')->first();
        
        ExchangeRate::create([
            'rate' => 36.50,
            'base_currency' => 'USD',
            'reference_date' => now(),
            'set_by' => $user->id,
            'is_active' => true,
        ]);

        $service = new ApuestaService();
        $usd = $service->bsToUsd(3650);
        
        $this->assertEquals(100.00, round($usd, 2));
    }

    public function test_convierte_usd_a_bs()
    {
        $user = User::where('email', 'super@lotto.com')->first();
        
        ExchangeRate::create([
            'rate' => 36.50,
            'base_currency' => 'USD',
            'reference_date' => now(),
            'set_by' => $user->id,
            'is_active' => true,
        ]);

        $service = new ApuestaService();
        $bs = $service->usdToBs(100);
        
        $this->assertEquals(3650.00, $bs);
    }

    public function test_lanza_excepcion_sin_tasa_activa()
    {
        $service = new ApuestaService();
        
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No hay tasa activa configurada');
        
        $service->calcularTotal(1000, 0);
    }

    public function test_obtiene_tasa_activa_null_si_no_existe()
    {
        $service = new ApuestaService();
        $tasa = $service->getTasaActiva();
        
        $this->assertNull($tasa);
    }

    public function test_obtiene_resumen_estadistico()
    {
        $user = User::where('email', 'super@lotto.com')->first();
        
        ExchangeRate::create([
            'rate' => 36.50,
            'base_currency' => 'USD',
            'reference_date' => now(),
            'set_by' => $user->id,
            'is_active' => true,
        ]);

        // Usar juego del seeder
        $juego = Juego::where('slug', 'animalitos')->first();
        
        if (!$juego) {
            $this->markTestSkipped('Juego Animalitos no existe');
        }

        $taquilla = \App\Models\Taquilla::factory()->create();
        
        Apuesta::create([
            'taquilla_id' => $taquilla->id,
            'juego_id' => $juego->id,
            'amount_bs' => 1800,
            'amount_usd' => 50,
            'exchange_rate_applied' => 36.50,
            'total_bs_equivalent' => 3625,
            'estado' => 'pendiente',
        ]);

        Apuesta::create([
            'taquilla_id' => $taquilla->id,
            'juego_id' => $juego->id,
            'amount_bs' => 0,
            'amount_usd' => 100,
            'exchange_rate_applied' => 36.50,
            'total_bs_equivalent' => 3650,
            'estado' => 'pagada',
        ]);

        $query = Apuesta::where('taquilla_id', $taquilla->id);
        $service = new ApuestaService();
        $resumen = $service->obtenerResumen($query);

        $this->assertEquals(1800, $resumen['total_bs']);
        $this->assertEquals(150, $resumen['total_usd']);
        $this->assertEquals(7275, $resumen['total_bet_amount_bs']);
        $this->assertEquals(1, $resumen['pending_count']);
        $this->assertEquals(1, $resumen['pagada_count']);
    }
}
