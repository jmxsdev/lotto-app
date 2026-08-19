<?php

namespace Tests\Unit;

use App\Models\Apuesta;
use App\Models\Banca;
use App\Models\ExchangeRate;
use App\Models\Grupo;
use App\Models\Juego;
use App\Models\JuegoLimite;
use App\Models\Taquilla;
use App\Models\User;
use App\Services\ApuestaService;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\JuegoAnimalitosSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApuestaServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->seed(JuegoAnimalitosSeeder::class);
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

        $service = new ApuestaService;
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

        $service = new ApuestaService;
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

        $service = new ApuestaService;
        $total = $service->calcularTotal(0, 100);

        $this->assertEquals(3650.00, $total);
    }

    public function test_valida_costo_minimo_pasa()
    {
        $juego = Juego::where('slug', 'lotto-activo')->first();

        if (! $juego) {
            $this->markTestSkipped('Juego Animalitos no existe');
        }

        $taquilla = $this->crearTaquillaConLimite($juego, 3600);
        $service = new ApuestaService;

        // Debe pasar (3625 >= 3600, límite del seeder)
        $resultado = $service->validarCostoMinimo(3625, $juego->id, $taquilla->id);
        $this->assertTrue($resultado['valid']);
        $this->assertEquals(3600, $resultado['limite_minimo']);
    }

    public function test_valida_costo_minimo_falla()
    {
        $juego = Juego::where('slug', 'lotto-activo')->first();

        if (! $juego) {
            $this->markTestSkipped('Juego Animalitos no existe');
        }

        $taquilla = $this->crearTaquillaConLimite($juego, 3600);
        $service = new ApuestaService;

        // Debe fallar (3500 < 3600, límite del seeder)
        $resultado = $service->validarCostoMinimo(3500, $juego->id, $taquilla->id);
        $this->assertFalse($resultado['valid']);
        $this->assertArrayHasKey('required_min', $resultado);
        $this->assertEquals(3600, $resultado['required_min']);
    }

    private function crearTaquillaConLimite($juego, $limiteMinimo)
    {
        $banca = Banca::create(['name' => 'Test', 'code' => 'TST']);
        $grupo = Grupo::create(['name' => 'Test', 'code' => 'TST', 'banca_id' => $banca->id]);
        $taquilla = Taquilla::create(['name' => 'Test', 'code' => 'TST', 'grupo_id' => $grupo->id, 'active' => true]);

        JuegoLimite::create([
            'juego_id' => $juego->id,
            'banca_id' => $banca->id,
            'moneda' => 'bs',
            'limite_minimo' => $limiteMinimo,
        ]);

        return $taquilla;
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

        $service = new ApuestaService;
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

        $service = new ApuestaService;
        $bs = $service->usdToBs(100);

        $this->assertEquals(3650.00, $bs);
    }

    public function test_lanza_excepcion_sin_tasa_activa()
    {
        ExchangeRate::query()->delete();

        $service = new ApuestaService;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No hay tasa activa configurada');

        $service->calcularTotal(1000, 0);
    }

    public function test_obtiene_tasa_activa_null_si_no_existe()
    {
        ExchangeRate::query()->delete();

        $service = new ApuestaService;
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
        $juego = Juego::where('slug', 'lotto-activo')->first();

        if (! $juego) {
            $this->markTestSkipped('Juego Animalitos no existe');
        }

        $taquilla = Taquilla::factory()->create();

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
        $service = new ApuestaService;
        $resumen = $service->obtenerResumen($query);

        $this->assertEquals(1800, $resumen['total_bs']);
        $this->assertEquals(150, $resumen['total_usd']);
        $this->assertEquals(7275, $resumen['total_bet_amount_bs']);
        $this->assertEquals(1, $resumen['pending_count']);
        $this->assertEquals(1, $resumen['pagada_count']);
    }

    // ============================================
    // Phase 2: Inheritance Resolvers Tests
    // ============================================

    public function test_get_effective_monedas_ambas_habilitadas_por_defecto()
    {
        $taquilla = Taquilla::factory()->create();

        $service = new ApuestaService;
        $monedas = $service->getEffectiveMonedas($taquilla->id);

        $this->assertTrue($monedas['bs']);
        $this->assertTrue($monedas['usd']);
    }

    public function test_get_effective_monedas_banca_restringe_usd()
    {
        $banca = Banca::create([
            'name' => 'Test Banca',
            'code' => 'TBM1',
            'monedas_permitidas' => ['bs' => true, 'usd' => false],
            'active' => true,
        ]);

        $grupo = Grupo::create([
            'name' => 'Test Grupo',
            'code' => 'TGM1',
            'banca_id' => $banca->id,
            'active' => true,
        ]);

        $taquilla = Taquilla::create([
            'name' => 'Test Taquilla',
            'code' => 'TTM1',
            'grupo_id' => $grupo->id,
            'active' => true,
        ]);

        $service = new ApuestaService;
        $monedas = $service->getEffectiveMonedas($taquilla->id);

        $this->assertTrue($monedas['bs']);
        $this->assertFalse($monedas['usd']);
    }

    public function test_get_effective_monedas_grupo_puede_ser_mas_restrictivo()
    {
        $banca = Banca::create([
            'name' => 'Test Banca 2',
            'code' => 'TBM2',
            'monedas_permitidas' => ['bs' => true, 'usd' => true],
            'active' => true,
        ]);

        $grupo = Grupo::create([
            'name' => 'Test Grupo 2',
            'code' => 'TGM2',
            'banca_id' => $banca->id,
            'monedas_permitidas' => ['bs' => true, 'usd' => false],
            'active' => true,
        ]);

        $taquilla = Taquilla::create([
            'name' => 'Test Taquilla 2',
            'code' => 'TTM2',
            'grupo_id' => $grupo->id,
            'active' => true,
        ]);

        $service = new ApuestaService;
        $monedas = $service->getEffectiveMonedas($taquilla->id);

        $this->assertTrue($monedas['bs']);
        $this->assertFalse($monedas['usd']);
    }

    public function test_get_effective_limit_cascade_taquilla_sobre_grupo()
    {
        $banca = Banca::create([
            'name' => 'Test Banca Limits',
            'code' => 'TBL1',
            'active' => true,
        ]);
        $grupo = Grupo::create([
            'name' => 'Test Grupo Limits',
            'code' => 'TGL1',
            'banca_id' => $banca->id,
            'active' => true,
        ]);
        $taquilla = Taquilla::create([
            'name' => 'Test Taquilla Limits',
            'code' => 'TTL1',
            'grupo_id' => $grupo->id,
            'active' => true,
        ]);

        $juego = Juego::where('slug', 'lotto-activo')->first();

        // Límite banca: max 200
        JuegoLimite::create([
            'juego_id' => $juego->id,
            'banca_id' => $banca->id,
            'moneda' => 'bs',
            'grupo_id' => null,
            'taquilla_id' => null,
            'limite_minimo' => 0,
            'limite_maximo' => 200,
        ]);

        // Límite grupo: max 100
        JuegoLimite::create([
            'juego_id' => $juego->id,
            'banca_id' => $banca->id,
            'moneda' => 'bs',
            'grupo_id' => $grupo->id,
            'taquilla_id' => null,
            'limite_minimo' => 0,
            'limite_maximo' => 100,
        ]);

        // Límite taquilla: max 50
        JuegoLimite::create([
            'juego_id' => $juego->id,
            'banca_id' => $banca->id,
            'moneda' => 'bs',
            'grupo_id' => $grupo->id,
            'taquilla_id' => $taquilla->id,
            'limite_minimo' => 0,
            'limite_maximo' => 50,
        ]);

        $service = new ApuestaService;
        $limite = $service->getEffectiveLimit($taquilla->id, $juego->id, 'bs');

        $this->assertNotNull($limite);
        $this->assertEquals(50, $limite->limite_maximo);
        $this->assertEquals($taquilla->id, $limite->taquilla_id);
    }

    public function test_get_effective_limit_fallback_to_banca()
    {
        $banca = Banca::create([
            'name' => 'Test Banca Fallback',
            'code' => 'TBF1',
            'active' => true,
        ]);
        $grupo = Grupo::create([
            'name' => 'Test Grupo Fallback',
            'code' => 'TGF1',
            'banca_id' => $banca->id,
            'active' => true,
        ]);
        $taquilla = Taquilla::create([
            'name' => 'Test Taquilla Fallback',
            'code' => 'TTF1',
            'grupo_id' => $grupo->id,
            'active' => true,
        ]);

        $juego = Juego::where('slug', 'lotto-activo')->first();

        // Solo límite a nivel banca (sin grupo ni taquilla)
        JuegoLimite::create([
            'juego_id' => $juego->id,
            'banca_id' => $banca->id,
            'moneda' => 'bs',
            'grupo_id' => null,
            'taquilla_id' => null,
            'limite_minimo' => 3600,
            'limite_maximo' => 5000,
        ]);

        $service = new ApuestaService;
        $limite = $service->getEffectiveLimit($taquilla->id, $juego->id, 'bs');

        $this->assertNotNull($limite);
        $this->assertEquals(5000, $limite->limite_maximo);
        $this->assertNull($limite->grupo_id);
        $this->assertNull($limite->taquilla_id);
    }

    public function test_vigencia_taquilla_prevalece_sobre_grupo_y_banca()
    {
        $banca = Banca::create([
            'name' => 'Test Banca Vigencia',
            'code' => 'TBV1',
            'vigencia_premios' => 60,
            'active' => true,
        ]);
        $grupo = Grupo::create([
            'name' => 'Test Grupo Vigencia',
            'code' => 'TGV1',
            'banca_id' => $banca->id,
            'vigencia_premios' => 45,
            'active' => true,
        ]);
        $taquilla = Taquilla::create([
            'name' => 'Test Taquilla Vigencia',
            'code' => 'TTV1',
            'grupo_id' => $grupo->id,
            'vigencia_premios' => 15,
            'active' => true,
        ]);

        $service = new ApuestaService;
        $vigencia = $service->getEffectiveVigencia($taquilla->id);

        $this->assertEquals(15, $vigencia);
    }

    public function test_vigencia_hereda_de_grupo_cuando_taquilla_no_configurada()
    {
        $banca = Banca::create([
            'name' => 'Test Banca Vigencia 2',
            'code' => 'TBV2',
            'vigencia_premios' => 90,
            'active' => true,
        ]);
        $grupo = Grupo::create([
            'name' => 'Test Grupo Vigencia 2',
            'code' => 'TGV2',
            'banca_id' => $banca->id,
            'vigencia_premios' => 60,
            'active' => true,
        ]);
        $taquilla = Taquilla::create([
            'name' => 'Test Taquilla Vigencia 2',
            'code' => 'TTV2',
            'grupo_id' => $grupo->id,
            'vigencia_premios' => null,
            'active' => true,
        ]);

        $service = new ApuestaService;
        $vigencia = $service->getEffectiveVigencia($taquilla->id);

        $this->assertEquals(60, $vigencia);
    }

    public function test_validar_apuesta_mixta_rechazada_si_usd_deshabilitado()
    {
        $banca = Banca::create([
            'name' => 'Test Banca Mixta',
            'code' => 'TBMX1',
            'monedas_permitidas' => ['bs' => true, 'usd' => false],
            'active' => true,
        ]);
        $grupo = Grupo::create([
            'name' => 'Test Grupo Mixta',
            'code' => 'TGMX1',
            'banca_id' => $banca->id,
            'active' => true,
        ]);
        $taquilla = Taquilla::create([
            'name' => 'Test Taquilla Mixta',
            'code' => 'TTMX1',
            'grupo_id' => $grupo->id,
            'active' => true,
        ]);

        $juego = Juego::where('slug', 'lotto-activo')->first();

        $service = new ApuestaService;
        $result = $service->validarMonedaYLimites($taquilla->id, $juego->id, 100, 50);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('mixtas', $result['message']);
    }
}
