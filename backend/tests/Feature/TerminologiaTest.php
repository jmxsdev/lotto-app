<?php

namespace Tests\Feature;

use App\Models\Apuesta;
use App\Models\Banca;
use App\Models\Grupo;
use App\Models\Juego;
use App\Models\Taquilla;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PR 1 — terminologia-agencia.
 *
 * Verifica que los mensajes backend y las claves JSON de reportes usan
 * "agencia" en lugar de "taquilla", sin tocar identificadores internos
 * (tablas, rutas, roles, variables).
 */
class TerminologiaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\DatabaseSeeder::class);
        $this->seed(\Database\Seeders\JuegoAnimalitosSeeder::class);
    }

    private function superUser(): User
    {
        $super = User::where('email', 'super@lotto.com')->first();
        $super->assignRole('super_master');

        return $super;
    }

    /**
     * Jerarquía banca → grupo → taquilla con una apuesta asociada.
     */
    private function crearJerarquiaConVenta(): Taquilla
    {
        $banca = Banca::create(['name' => 'Banca Term', 'code' => 'BTERM', 'active' => true]);
        $grupo = Grupo::create(['name' => 'Grupo Term', 'code' => 'GTERM', 'banca_id' => $banca->id, 'active' => true]);
        $taquilla = Taquilla::create(['name' => 'Agencia Centro', 'code' => 'TERM1', 'grupo_id' => $grupo->id, 'active' => true]);

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

        return $taquilla;
    }

    // ---------------------------------------------------------------
    // R3 — Claves JSON de reportes usan "Agencia"
    // ---------------------------------------------------------------

    public function test_rendimiento_taquillas_usa_clave_agencia()
    {
        $taquilla = $this->crearJerarquiaConVenta();

        $response = $this->actingAs($this->superUser(), 'sanctum')
            ->getJson('/api/v1/reportes/rendimiento-taquillas');

        $response->assertStatus(200);
        $data = $response->json('data');

        $this->assertCount(1, $data);
        $this->assertArrayHasKey('Agencia', $data[0]);
        $this->assertArrayNotHasKey('Taquilla', $data[0]);
        $this->assertEquals($taquilla->name, $data[0]['Agencia']);
    }

    public function test_relacion_tickets_usa_clave_agencia()
    {
        $taquilla = $this->crearJerarquiaConVenta();

        Ticket::create([
            'taquilla_id' => $taquilla->id,
            'total_bs' => 1000,
            'total_usd' => 0,
            'estado' => 'pendiente',
        ]);

        $response = $this->actingAs($this->superUser(), 'sanctum')
            ->getJson('/api/v1/reportes/relacion-tickets');

        $response->assertStatus(200);
        $data = $response->json('data');

        $this->assertNotEmpty($data);
        $this->assertArrayHasKey('Agencia', $data[0]);
        $this->assertArrayNotHasKey('Taquilla', $data[0]);
        $this->assertEquals($taquilla->name, $data[0]['Agencia']);
    }

    public function test_vencidos_usa_clave_agencia()
    {
        $taquilla = $this->crearJerarquiaConVenta();

        Ticket::create([
            'taquilla_id' => $taquilla->id,
            'total_bs' => 500,
            'total_usd' => 0,
            'estado' => 'vencido',
        ]);

        $response = $this->actingAs($this->superUser(), 'sanctum')
            ->getJson('/api/v1/reportes/vencidos');

        $response->assertStatus(200);
        $data = $response->json('data');

        $this->assertNotEmpty($data);
        $this->assertArrayHasKey('Agencia', $data[0]);
        $this->assertArrayNotHasKey('Taquilla', $data[0]);
        $this->assertEquals($taquilla->name, $data[0]['Agencia']);
    }

    // ---------------------------------------------------------------
    // R2 — Mensajes backend usan "agencia"
    // ---------------------------------------------------------------

    public function test_verify_mac_mensaje_agencia_desactivada()
    {
        $taquilla = Taquilla::factory()->create(['active' => false]);
        $user = User::factory()->create([
            'taquilla_id' => $taquilla->id,
            'role' => 'taquilla',
        ]);
        $user->assignRole('taquilla');

        $response = $this->withHeaders(['X-Device-MAC' => 'AA:BB:CC:DD:EE:FF'])
            ->actingAs($user, 'sanctum')
            ->getJson('/api/v1/apuestas');

        $response->assertStatus(403)
            ->assertJsonPath('message', 'La agencia está desactivada.');
    }

    public function test_login_panel_rechaza_agencia_con_mensaje()
    {
        // Usuario demo pre-activado del seeder (rol taquilla, fingerprint demo-device-001)
        $response = $this->withHeaders([
            'X-Panel' => 'true',
            'X-Device-Fingerprint' => 'demo-device-001',
        ])->postJson('/api/v1/login', [
            'email' => 'demo@lotto.com',
            'password' => 'password',
        ]);

        $response->assertStatus(403)
            ->assertJsonPath('message', 'Las agencias deben usar la app de escritorio.');
    }

    public function test_activacion_mensaje_agencia_activada()
    {
        // Taquilla del seeder con activation_code 'ABCDE' y active=false
        $response = $this->postJson('/api/v1/activar', [
            'activation_code' => 'ABCDE',
            'mac_address' => 'AA:BB:CC:DD:EE:FF',
            'device_fingerprint' => 'fp-term-001',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Agencia activada exitosamente.');
    }

    public function test_eliminar_taquilla_mensaje_agencia()
    {
        $taquilla = Taquilla::factory()->create();

        $response = $this->actingAs($this->superUser(), 'sanctum')
            ->deleteJson('/api/v1/taquillas/' . $taquilla->id);

        $response->assertStatus(200)
            ->assertJsonPath('message', 'Agencia eliminada correctamente.');

        $this->assertSoftDeleted('taquillas', ['id' => $taquilla->id]);
    }

    public function test_crear_apuesta_sin_agencia_mensaje()
    {
        $juego = Juego::where('slug', 'lotto-activo')->first();

        $response = $this->actingAs($this->superUser(), 'sanctum')
            ->postJson('/api/v1/apuestas', [
                'juego_id' => $juego->id,
                'amount_bs' => 100,
                'amount_usd' => 0,
                'combinacion' => ['animal' => 'perro', 'numero' => 1],
            ]);

        $response->assertStatus(403)
            ->assertJsonPath('message', 'Solo las agencias pueden crear apuestas.');
    }

    public function test_crear_ticket_sin_agencia_mensaje()
    {
        $juego = Juego::where('slug', 'lotto-activo')->first();

        $response = $this->actingAs($this->superUser(), 'sanctum')
            ->postJson('/api/v1/tickets', [
                'lines' => [
                    ['juego_id' => $juego->id, 'amount_bs' => 100, 'amount_usd' => 0],
                ],
            ]);

        $response->assertStatus(403)
            ->assertJsonPath('message', 'Solo las agencias pueden crear tickets.');
    }

    // ---------------------------------------------------------------
    // R4 — Identificadores internos intactos
    // ---------------------------------------------------------------

    public function test_identificadores_internos_intactos()
    {
        $taquilla = $this->crearJerarquiaConVenta();

        // La ruta /api/v1/taquillas sigue existiendo
        $response = $this->actingAs($this->superUser(), 'sanctum')
            ->getJson('/api/v1/taquillas');

        $response->assertStatus(200);

        // El rol 'taquilla' sigue existiendo
        $user = User::where('email', 'taquilla@lotto.com')->first();
        $this->assertTrue($user->hasRole('taquilla'));

        // La tabla y el modelo siguen usando taquillas/Taquilla
        $this->assertDatabaseHas('taquillas', ['id' => $taquilla->id]);
        $this->assertInstanceOf(\App\Models\Taquilla::class, $taquilla);
    }
}
