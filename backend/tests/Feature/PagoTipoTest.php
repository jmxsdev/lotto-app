<?php

namespace Tests\Feature;

use App\Models\Apuesta;
use App\Models\Juego;
use App\Models\Taquilla;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\JuegoAnimalitosSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PR 2 — pagos.tipo enum devolucion.
 *
 * La API acepta pagos con tipo='devolucion' (enum ampliado a nivel de
 * base de datos en MySQL; SQLite trata enum como varchar sin restricción).
 */
class PagoTipoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->seed(JuegoAnimalitosSeeder::class);
    }

    public function test_pago_tipo_devolucion_aceptado()
    {
        $juego = Juego::where('slug', 'lotto-activo')->first();

        $taquilla = Taquilla::factory()->create();
        $taquilla->update(['mac_address' => 'AA:BB:CC:DD:EE:FF']);

        $taquillaUser = User::factory()->create([
            'taquilla_id' => $taquilla->id,
            'role' => 'taquilla',
        ]);
        $taquillaUser->assignRole('taquilla');

        $apuesta = Apuesta::create([
            'taquilla_id' => $taquilla->id,
            'juego_id' => $juego->id,
            'amount_bs' => 1000,
            'amount_usd' => 0,
            'exchange_rate_applied' => 36.50,
            'total_bs_equivalent' => 1000,
            'estado' => 'pendiente',
        ]);

        $response = $this->withHeaders(['X-Device-MAC' => 'AA:BB:CC:DD:EE:FF'])
            ->actingAs($taquillaUser, 'sanctum')
            ->postJson('/api/v1/pagos', [
                'apuesta_id' => $apuesta->id,
                'tipo' => 'devolucion',
                'moneda' => 'bs',
                'amount_bs' => 0,
                'amount_usd' => 0,
                'concepto' => 'Devolución de dinero',
            ]);

        $response->assertStatus(201);

        $this->assertDatabaseHas('pagos', [
            'apuesta_id' => $apuesta->id,
            'tipo' => 'devolucion',
        ]);
    }
}
