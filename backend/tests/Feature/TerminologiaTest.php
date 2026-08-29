<?php

namespace Tests\Feature;

use App\Models\Agencia;
use App\Models\Apuesta;
use App\Models\Banca;
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
        $this->seed(DatabaseSeeder::class);
        $this->seed(JuegoAnimalitosSeeder::class);
    }

    private function superUser(): User
    {
        $super = User::where('email', 'super@lotto.com')->first();
        $super->assignRole('super_master');

        return $super;
    }

    /**
     * Jerarquía banca → grupo → local (agencia) → taquilla (máquina)
     * con una apuesta asociada.
     */
    private function crearJerarquiaConVenta(): Taquilla
    {
        $banca = Banca::create(['name' => 'Banca Term', 'code' => 'BTERM', 'active' => true]);
        $grupo = Grupo::create(['name' => 'Grupo Term', 'code' => 'GTERM', 'banca_id' => $banca->id, 'active' => true]);
        $local = Agencia::factory()->create(['name' => 'Local Term', 'grupo_id' => $grupo->id, 'active' => true]);
        $taquilla = Taquilla::create(['name' => 'Taquilla Term', 'code' => 'TERM1', 'grupo_id' => $grupo->id, 'agencia_id' => $local->id, 'active' => true]);

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
    // R3 — Claves JSON de reportes: "Agencia"=local, "Taquilla"=máquina
    // ---------------------------------------------------------------

    public function test_rendimiento_nivel_taquilla_usa_clave_taquilla()
    {
        $taquilla = $this->crearJerarquiaConVenta();

        $response = $this->actingAs($this->superUser(), 'sanctum')
            ->getJson('/api/v1/reportes/rendimiento-taquillas');

        $response->assertStatus(200);
        $data = $response->json('data');

        $this->assertCount(1, $data);
        $this->assertArrayHasKey('Taquilla', $data[0]);
        $this->assertArrayNotHasKey('Agencia', $data[0]);
        $this->assertEquals($taquilla->name, $data[0]['Taquilla'], 'Taquilla = máquina');
    }

    public function test_rendimiento_nivel_agencia_usa_clave_agencia()
    {
        $taquilla = $this->crearJerarquiaConVenta();

        $response = $this->actingAs($this->superUser(), 'sanctum')
            ->getJson('/api/v1/reportes/rendimiento-taquillas?nivel=agencia');

        $response->assertStatus(200);
        $data = $response->json('data');

        $this->assertCount(1, $data);
        $this->assertArrayHasKey('Agencia', $data[0]);
        $this->assertArrayNotHasKey('Taquilla', $data[0]);
        $this->assertEquals($taquilla->agencia->name, $data[0]['Agencia'], 'Agencia = local');
    }

    public function test_relacion_tickets_usa_clave_agencia_local_y_taquilla_maquina()
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
        $this->assertArrayHasKey('Taquilla', $data[0]);
        $this->assertEquals($taquilla->agencia->name, $data[0]['Agencia'], 'Agencia = local');
        $this->assertEquals($taquilla->name, $data[0]['Taquilla'], 'Taquilla = máquina');
    }

    public function test_vencidos_usa_clave_agencia_local_y_taquilla_maquina()
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
        $this->assertArrayHasKey('Taquilla', $data[0]);
        $this->assertEquals($taquilla->agencia->name, $data[0]['Agencia'], 'Agencia = local');
        $this->assertEquals($taquilla->name, $data[0]['Taquilla'], 'Taquilla = máquina');
    }

    // ---------------------------------------------------------------
    // R2 — Mensajes backend usan "agencia"
    // ---------------------------------------------------------------

    public function test_verify_mac_mensaje_taquilla_desactivada()
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
            ->assertJsonPath('message', 'La taquilla está desactivada.');
    }

    public function test_login_panel_rechaza_taquilla_con_mensaje()
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
            ->assertJsonPath('message', 'Las taquillas deben usar la app de escritorio.');
    }

    public function test_activacion_mensaje_taquilla_activada()
    {
        // Taquilla del seeder con activation_code 'ABCDE' y active=false
        $response = $this->postJson('/api/v1/activar', [
            'activation_code' => 'ABCDE',
            'mac_address' => 'AA:BB:CC:DD:EE:FF',
            'device_fingerprint' => 'fp-term-001',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Taquilla activada exitosamente.');
    }

    public function test_eliminar_taquilla_mensaje_taquilla()
    {
        $taquilla = Taquilla::factory()->create();

        $response = $this->actingAs($this->superUser(), 'sanctum')
            ->deleteJson('/api/v1/taquillas/'.$taquilla->id);

        $response->assertStatus(200)
            ->assertJsonPath('message', 'Taquilla eliminada correctamente.');

        $this->assertSoftDeleted('taquillas', ['id' => $taquilla->id]);
    }

    public function test_moneda_no_permitida_apuesta_mensaje_taquilla()
    {
        $user = User::where('email', 'super@lotto.com')->first();

        $banca = Banca::create([
            'name' => 'Banca Moneda', 'code' => 'BMON', 'active' => true,
            'monedas_permitidas' => ['bs' => true, 'usd' => false],
        ]);
        $grupo = Grupo::create(['name' => 'Grupo Moneda', 'code' => 'GMON', 'banca_id' => $banca->id, 'active' => true]);
        $taquilla = Taquilla::create(['name' => 'Taquilla Moneda', 'code' => 'TMON', 'grupo_id' => $grupo->id, 'active' => true, 'mac_address' => 'AA:BB:CC:DD:EE:FF']);

        $taquillaUser = User::factory()->create(['taquilla_id' => $taquilla->id, 'role' => 'taquilla']);
        $taquillaUser->assignRole('taquilla');

        $juego = Juego::where('slug', 'lotto-activo')->first();

        $response = $this->withHeaders(['X-Device-MAC' => 'AA:BB:CC:DD:EE:FF'])
            ->actingAs($taquillaUser, 'sanctum')
            ->postJson('/api/v1/apuestas', [
                'juego_id' => $juego->id,
                'combinacion' => ['animal' => 'perro', 'numero' => 5],
                'amount_bs' => 0,
                'amount_usd' => 50,
                'sorteo_hora' => now()->addHours(2)->format('Y-m-d H:i:s'),
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'Moneda USD no permitida para esta taquilla.');
    }

    public function test_moneda_no_permitida_ticket_mensaje_taquilla()
    {
        $banca = Banca::create([
            'name' => 'Banca Ticket', 'code' => 'BTIC', 'active' => true,
            'monedas_permitidas' => ['bs' => true, 'usd' => false],
        ]);
        $grupo = Grupo::create(['name' => 'Grupo Ticket', 'code' => 'GTIC', 'banca_id' => $banca->id, 'active' => true]);
        $taquilla = Taquilla::create(['name' => 'Taquilla Ticket', 'code' => 'TTIC', 'grupo_id' => $grupo->id, 'active' => true, 'mac_address' => 'AA:BB:CC:DD:EE:FF']);

        $taquillaUser = User::factory()->create(['taquilla_id' => $taquilla->id, 'role' => 'taquilla']);
        $taquillaUser->assignRole('taquilla');

        $juego = Juego::where('slug', 'lotto-activo')->first();

        $response = $this->withHeaders(['X-Device-MAC' => 'AA:BB:CC:DD:EE:FF'])
            ->actingAs($taquillaUser, 'sanctum')
            ->postJson('/api/v1/tickets', [
                'lines' => [
                    ['juego_id' => $juego->id, 'amount_bs' => 0, 'amount_usd' => 50],
                ],
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'Moneda USD no permitida para esta taquilla.');
    }

    public function test_vigencia_taquilla_mensaje_taquilla()
    {
        $banca = Banca::create(['name' => 'Banca Vig', 'code' => 'BVIG', 'active' => true]);
        $grupo = Grupo::create(['name' => 'Grupo Vig', 'code' => 'GVIG', 'banca_id' => $banca->id, 'active' => true, 'vigencia_premios' => 5]);

        $response = $this->actingAs($this->superUser(), 'sanctum')
            ->postJson('/api/v1/taquillas', [
                'name' => 'Taquilla Vig',
                'code' => 'TVIG',
                'grupo_id' => $grupo->id,
                'vigencia_premios' => 10,
                'user_name' => 'Usuario Taquilla',
                'user_email' => 'taquilla-vig@test.com',
                'user_password' => 'password123',
            ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('de la taquilla', $response->json('message'));
    }

    public function test_tiempo_taquilla_mensaje_taquilla()
    {
        $banca = Banca::create(['name' => 'Banca Tmp', 'code' => 'BTMP', 'active' => true]);
        $grupo = Grupo::create(['name' => 'Grupo Tmp', 'code' => 'GTMP', 'banca_id' => $banca->id, 'active' => true, 'tiempo_eliminacion' => 5]);

        $response = $this->actingAs($this->superUser(), 'sanctum')
            ->postJson('/api/v1/taquillas', [
                'name' => 'Taquilla Tmp',
                'code' => 'TTMP',
                'grupo_id' => $grupo->id,
                'tiempo_eliminacion' => 10,
                'user_name' => 'Usuario Taquilla',
                'user_email' => 'taquilla-tmp@test.com',
                'user_password' => 'password123',
            ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('de la taquilla', $response->json('message'));
    }

    public function test_dispositivo_no_registrado_mensaje_taquilla()
    {
        $response = $this->postJson('/api/v1/dispositivo/verificar', [
            'device_fingerprint' => 'fingerprint-desconocido-001',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('message', 'Dispositivo no registrado. Active su taquilla.');
    }

    public function test_eliminar_grupo_con_taquillas_mensaje_taquillas()
    {
        $banca = Banca::factory()->create();
        $grupo = Grupo::factory()->create(['banca_id' => $banca->id]);
        Taquilla::factory()->create(['grupo_id' => $grupo->id]);

        $response = $this->actingAs($this->superUser(), 'sanctum')
            ->deleteJson('/api/v1/grupos/'.$grupo->id);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'No se puede eliminar el grupo porque tiene taquillas asociadas.');
    }

    public function test_crear_apuesta_sin_taquilla_mensaje()
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
            ->assertJsonPath('message', 'Solo las taquillas pueden crear apuestas.');
    }

    public function test_crear_ticket_sin_taquilla_mensaje()
    {
        $juego = Juego::where('slug', 'lotto-activo')->first();

        $response = $this->actingAs($this->superUser(), 'sanctum')
            ->postJson('/api/v1/tickets', [
                'lines' => [
                    ['juego_id' => $juego->id, 'amount_bs' => 100, 'amount_usd' => 0],
                ],
            ]);

        $response->assertStatus(403)
            ->assertJsonPath('message', 'Solo las taquillas pueden crear tickets.');
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
        $this->assertInstanceOf(Taquilla::class, $taquilla);
    }
}
