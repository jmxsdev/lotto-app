<?php

namespace Tests\Feature;

use App\Models\Grupo;
use App\Models\Taquilla;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Comando one-off `taquillas:sanear-activas`: corrige las taquillas heredadas
 * del bug de activación (active=true con mac_address=null → active=false).
 *
 * Dry-run por defecto; `--apply` escribe. Idempotente por el `where`.
 */
class TaquillasSanearActivasCommandTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Crea una taquilla afectada por el bug: activa y sin MAC.
     */
    private function taquillaAfectada(Grupo $grupo): Taquilla
    {
        return Taquilla::factory()->create([
            'grupo_id' => $grupo->id,
            'active' => true,
            'mac_address' => null,
        ]);
    }

    public function test_dry_run_reporta_conteo_e_ids_sin_modificar(): void
    {
        $grupo = Grupo::factory()->create(['active' => true]);

        // 12 afectadas: cubre el límite de 10 IDs mostrados.
        for ($i = 0; $i < 12; $i++) {
            $this->taquillaAfectada($grupo);
        }

        $this->artisan('taquillas:sanear-activas')
            ->expectsOutputToContain('Dry-run: 12')
            ->expectsOutputToContain('... y 2 más.')
            ->assertSuccessful();

        // El dry-run no modifica la BD.
        $this->assertSame(
            12,
            Taquilla::where('active', true)->whereNull('mac_address')->count()
        );
    }

    public function test_apply_solo_sanea_active_true_sin_mac(): void
    {
        $grupo = Grupo::factory()->create(['active' => true]);

        $afectada = $this->taquillaAfectada($grupo);
        $conMac = Taquilla::factory()->create([
            'grupo_id' => $grupo->id,
            'active' => true,
            'mac_address' => 'AA:BB:CC:DD:EE:FF',
        ]);
        $inactiva = Taquilla::factory()->create([
            'grupo_id' => $grupo->id,
            'active' => false,
            'mac_address' => null,
        ]);

        $this->artisan('taquillas:sanear-activas', ['--apply' => true])
            ->expectsOutputToContain('Saneo aplicado: 1')
            ->assertSuccessful();

        // Solo la activa sin MAC pasa a inactiva.
        $this->assertDatabaseHas('taquillas', ['id' => $afectada->id, 'active' => false]);
        // Las que tienen MAC o ya están inactivas no se tocan.
        $this->assertDatabaseHas('taquillas', ['id' => $conMac->id, 'active' => true]);
        $this->assertDatabaseHas('taquillas', ['id' => $inactiva->id, 'active' => false]);
    }

    public function test_apply_idempotente_segunda_corrida_reporta_cero(): void
    {
        $grupo = Grupo::factory()->create(['active' => true]);
        $this->taquillaAfectada($grupo);

        $this->artisan('taquillas:sanear-activas', ['--apply' => true])
            ->expectsOutputToContain('Saneo aplicado: 1')
            ->assertSuccessful();

        // Re-ejecución: no quedan afectadas.
        $this->artisan('taquillas:sanear-activas', ['--apply' => true])
            ->expectsOutputToContain('Saneo aplicado: 0')
            ->assertSuccessful();

        $this->assertSame(
            0,
            Taquilla::where('active', true)->whereNull('mac_address')->count()
        );
    }

    public function test_sin_afectadas_reporta_cero_sin_cambios(): void
    {
        // Sin taquillas afectadas (ni filas): ambos modos reportan 0.
        $this->artisan('taquillas:sanear-activas')
            ->expectsOutputToContain('Dry-run: 0')
            ->assertSuccessful();

        $this->artisan('taquillas:sanear-activas', ['--apply' => true])
            ->expectsOutputToContain('Saneo aplicado: 0')
            ->assertSuccessful();

        $this->assertSame(0, Taquilla::count());
    }
}
