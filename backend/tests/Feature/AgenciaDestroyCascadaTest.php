<?php

namespace Tests\Feature;

use App\Models\Agencia;
use App\Models\Apuesta;
use App\Models\Banca;
use App\Models\ExchangeRate;
use App\Models\Grupo;
use App\Models\Juego;
use App\Models\Taquilla;
use App\Models\Ticket;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\JuegoAnimalitosSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * FIX WARNING VERIFY — borrado en cascada de un local (agencia).
 *
 * Decisión del cliente: al borrar un local se aplica CASCADA (ya NO set null):
 * - taquillas: SOFT-DELETE (conservan agencia_id; el historial de apuestas/
 *   pagos/cierres queda intacto y trazable en reportes).
 * - usuarios rol taquilla del local: se DESACTIVAN (active=false) conservando
 *   agencia_id (User no tiene SoftDeletes; se conserva el registro).
 * - apuestas/pagos/cierres NO se tocan.
 * - el destroy NUNCA deja taquillas con agencia_id null (regla de negocio:
 *   la taquilla SIEMPRE tiene local asignado).
 * - permisos intactos: solo quien puede borrar el local lo hace (403 en roles
 *   sin permiso).
 */
class AgenciaDestroyCascadaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->seed(JuegoAnimalitosSeeder::class);
    }

    private function superUser(): User
    {
        $super = User::where('email', 'super@lotto.com')->first();
        $super->assignRole('super_master');

        return $super;
    }

    private function bancaUser(): User
    {
        $user = User::where('email', 'banca@lotto.com')->first();
        $user->assignRole('banca');

        return $user;
    }

    private function agenciaUser(): User
    {
        $user = User::where('email', 'agencia@lotto.com')->first();
        $user->assignRole('agencia');

        return $user;
    }

    /**
     * Jerarquía aislada: banca → grupo → local → taquilla → usuario rol taquilla.
     *
     * @return array{0: Banca, 1: Grupo, 2: Agencia, 3: Taquilla, 4: User}
     */
    private function crearJerarquiaConTaquilla(string $prefijo): array
    {
        $banca = Banca::factory()->create([
            'name' => "Banca {$prefijo}",
            'code' => "DCB-{$prefijo}",
            'active' => true,
        ]);

        $grupo = Grupo::factory()->create([
            'name' => "Grupo {$prefijo}",
            'code' => "DCG-{$prefijo}",
            'banca_id' => $banca->id,
            'active' => true,
        ]);

        $local = Agencia::factory()->create([
            'name' => "Local {$prefijo}",
            'code' => "DCL-{$prefijo}",
            'grupo_id' => $grupo->id,
            'active' => true,
        ]);

        $taquilla = Taquilla::factory()->create([
            'name' => "Taquilla {$prefijo}",
            'code' => "DCT-{$prefijo}",
            'grupo_id' => $grupo->id,
            'agencia_id' => $local->id,
            'active' => true,
        ]);

        $usuario = User::factory()->create([
            'role' => 'taquilla',
            'taquilla_id' => $taquilla->id,
            'agencia_id' => $local->id,
            'active' => true,
        ]);
        $usuario->assignRole('taquilla');

        return [$banca, $grupo, $local, $taquilla, $usuario];
    }

    public function test_destroy_local_con_taquillas_aplica_cascada_y_conserva_historial()
    {
        [, $grupo, $local, $taquilla, $usuario] = $this->crearJerarquiaConTaquilla('Cascade');

        // Historial previo al borrado: una apuesta (y su pago queda enlazado a la taquilla)
        $juego = Juego::where('slug', 'lotto-activo')->first();
        Apuesta::create([
            'taquilla_id' => $taquilla->id,
            'juego_id' => $juego->id,
            'amount_bs' => 1000,
            'amount_usd' => 0,
            'exchange_rate_applied' => 36.50,
            'total_bs_equivalent' => 1000,
            'estado' => 'pendiente',
            'fecha_hora' => now()->subDay(),
        ]);
        $apuestaId = Apuesta::first()->id;

        $response = $this->actingAs($this->superUser(), 'sanctum')
            ->deleteJson("/api/v1/agencias/{$local->id}");

        $response->assertStatus(200);

        // El local queda soft-deleted
        $this->assertSoftDeleted('agencias', ['id' => $local->id]);

        // CASCADA: la taquilla queda soft-deleted y CONSERVA su local (nunca null)
        $this->assertSoftDeleted('taquillas', ['id' => $taquilla->id]);
        $this->assertDatabaseHas('taquillas', ['id' => $taquilla->id, 'agencia_id' => $local->id]);

        // El usuario rol taquilla se DESACTIVA y conserva su local
        $this->assertDatabaseHas('users', ['id' => $usuario->id, 'agencia_id' => $local->id, 'active' => false]);

        // El destroy NUNCA deja taquillas sin local en el grupo del local borrado
        $this->assertSame(
            0,
            Taquilla::withTrashed()->where('grupo_id', $grupo->id)->whereNull('agencia_id')->count(),
            'Ninguna taquilla del grupo debe quedar con agencia_id null tras la cascada'
        );

        // El historial de apuestas se conserva intacto
        $this->assertDatabaseHas('apuestas', ['id' => $apuestaId, 'taquilla_id' => $taquilla->id]);
    }

    public function test_destroy_local_sin_taquillas_borrado_normal()
    {
        $banca = Banca::factory()->create(['name' => 'Banca Sola', 'code' => 'DCS-SOLO', 'active' => true]);
        $grupo = Grupo::factory()->create(['name' => 'Grupo Sola', 'code' => 'DCS-GRUPO', 'banca_id' => $banca->id, 'active' => true]);
        $local = Agencia::factory()->create(['name' => 'Local Sola', 'code' => 'DCS-LOCAL', 'grupo_id' => $grupo->id, 'active' => true]);

        $response = $this->actingAs($this->superUser(), 'sanctum')
            ->deleteJson("/api/v1/agencias/{$local->id}");

        $response->assertStatus(200);
        $this->assertSoftDeleted('agencias', ['id' => $local->id]);
        $this->assertSame(0, Taquilla::where('agencia_id', $local->id)->count());
    }

    public function test_destroy_403_para_rol_agencia()
    {
        [, , $local] = $this->crearJerarquiaConTaquilla('AgNoGestiona');

        // El rol agencia NO gestiona agencias: ni siquiera su propio local
        $response = $this->actingAs($this->agenciaUser(), 'sanctum')
            ->deleteJson("/api/v1/agencias/{$local->id}");

        $response->assertStatus(403);
        $this->assertDatabaseHas('agencias', ['id' => $local->id, 'deleted_at' => null]);
    }

    public function test_destroy_403_para_banca_de_otra_banca()
    {
        [, , $localAjeno] = $this->crearJerarquiaConTaquilla('Ajeno');
        $this->crearJerarquiaConTaquilla('Propia');

        $response = $this->actingAs($this->bancaUser(), 'sanctum')
            ->deleteJson("/api/v1/agencias/{$localAjeno->id}");

        $response->assertStatus(403);
        $this->assertDatabaseHas('agencias', ['id' => $localAjeno->id, 'deleted_at' => null]);
    }

    public function test_reportes_no_se_rompen_y_conservan_historial_con_taquillas_trashed()
    {
        $super = $this->superUser();

        ExchangeRate::create([
            'rate' => 36.50,
            'base_currency' => 'USD',
            'reference_date' => now(),
            'set_by' => $super->id,
            'is_active' => true,
        ]);

        [, , $local, $taquilla] = $this->crearJerarquiaConTaquilla('Reporte');

        $juego = Juego::where('slug', 'lotto-activo')->first();
        Apuesta::create([
            'taquilla_id' => $taquilla->id,
            'juego_id' => $juego->id,
            'amount_bs' => 1000,
            'amount_usd' => 0,
            'exchange_rate_applied' => 36.50,
            'total_bs_equivalent' => 1000,
            'estado' => 'pendiente',
            'fecha_hora' => now()->subDay(),
        ]);

        Ticket::create([
            'taquilla_id' => $taquilla->id,
            'total_bs' => 1000,
            'total_usd' => 0,
            'estado' => 'pendiente',
        ]);

        // Cascada: se borra el local y con él sus taquillas (soft delete)
        $this->actingAs($super, 'sanctum')
            ->deleteJson("/api/v1/agencias/{$local->id}")
            ->assertStatus(200);

        // 1. Ventas totales por MÁQUINA: la taquilla trashed sigue en el historial
        $response = $this->actingAs($super, 'sanctum')
            ->getJson('/api/v1/reportes/ventas-totales?nivel=taquilla');
        $response->assertStatus(200);
        $filaTaquilla = collect($response->json('data'))->firstWhere('Entidad', $taquilla->name);
        $this->assertNotNull($filaTaquilla, 'La taquilla borrada debe conservar su fila de ventas');
        $this->assertEquals(1000, $filaTaquilla['Venta']);

        // 2. Ventas totales por LOCAL: el local trashed conserva su fila
        $response = $this->actingAs($super, 'sanctum')
            ->getJson('/api/v1/reportes/ventas-totales?nivel=agencia');
        $response->assertStatus(200);
        $filaLocal = collect($response->json('data'))->firstWhere('Entidad', $local->name);
        $this->assertNotNull($filaLocal, 'El local borrado debe conservar su fila de ventas');
        $this->assertEquals(1000, $filaLocal['Venta']);

        // 5. Relación de tickets: conserva los labels local/máquina de la taquilla trashed
        $response = $this->actingAs($super, 'sanctum')
            ->getJson('/api/v1/reportes/relacion-tickets');
        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertNotEmpty($data);
        $this->assertEquals($local->name, $data[0]['Agencia'], 'El ticket histórico conserva el label del local');
        $this->assertEquals($taquilla->name, $data[0]['Taquilla'], 'El ticket histórico conserva el label de la máquina');
    }
}
