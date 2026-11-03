<?php

namespace Tests\Feature;

use App\Models\Apuesta;
use App\Models\Banca;
use App\Models\CierreCaja;
use App\Models\Grupo;
use App\Models\Juego;
use App\Models\JuegoLimite;
use App\Models\Taquilla;
use App\Models\User;
use App\Services\CierreService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Contrato Spatie post-`drop` (WU-7): autorización, scoping, login y las
 * queries ocultas (`role(...)`) resuelven siempre por Spatie, la única fuente
 * de verdad (REQ-1, REQ-4, REQ-5, REQ-6, REQ-9).
 *
 * Los casos nacieron como pruebas de divergencia contra la columna legacy
 * `users.role`; tras el Merge 2 esa columna ya no existe, así que cada caso
 * queda como test de contrato del rol Spatie.
 */
class DivergenciaRolTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    // ==================================================
    // Helpers
    // ==================================================

    /**
     * @return array{0: Banca, 1: Grupo, 2: Taquilla}
     */
    private function crearCadena(string $sufijo): array
    {
        $banca = Banca::create([
            'name' => 'Banca Divergencia '.$sufijo,
            'code' => 'BDIV'.$sufijo.uniqid(),
            'active' => true,
        ]);

        $grupo = Grupo::create([
            'name' => 'Grupo Divergencia '.$sufijo,
            'code' => 'GDIV'.$sufijo.uniqid(),
            'banca_id' => $banca->id,
            'active' => true,
        ]);

        $taquilla = Taquilla::create([
            'name' => 'Taquilla Divergencia '.$sufijo,
            'code' => 'TDIV'.$sufijo.uniqid(),
            'grupo_id' => $grupo->id,
            'active' => true,
        ]);

        return [$banca, $grupo, $taquilla];
    }

    private function crearJuego(): Juego
    {
        return Juego::create([
            'name' => 'Juego Divergencia '.uniqid(),
            'slug' => 'juego-divergencia-'.uniqid(),
            'type' => 'terminales',
            'active' => true,
        ]);
    }

    private function limite(int $juegoId, int $bancaId, ?int $grupoId, ?int $taquillaId, float $porcentajePago): void
    {
        JuegoLimite::create([
            'juego_id' => $juegoId,
            'banca_id' => $bancaId,
            'grupo_id' => $grupoId,
            'taquilla_id' => $taquillaId,
            'moneda' => 'bs',
            'porcentaje_pago' => $porcentajePago,
        ]);
    }

    private function apuesta(int $juegoId, int $taquillaId, float $amountBs, ?string $fechaHora = null): Apuesta
    {
        return Apuesta::create([
            'taquilla_id' => $taquillaId,
            'juego_id' => $juegoId,
            'amount_bs' => $amountBs,
            'amount_usd' => 0,
            'exchange_rate_applied' => 36.5,
            'total_bs_equivalent' => $amountBs,
            'estado' => 'pendiente',
            'fecha_hora' => $fechaHora ?? now(),
        ]);
    }

    private function cierre(Taquilla $taquilla, User $creador): CierreCaja
    {
        return CierreCaja::create([
            'taquilla_id' => $taquilla->id,
            'fecha_inicio' => now()->subDay(),
            'fecha_fin' => now(),
            'exchange_rate_cierre' => 36.5,
            'created_by' => $creador->id,
        ]);
    }

    // ==================================================
    // Caso 1 — comisiones: alcance del rol Spatie master
    // ==================================================

    public function test_comision_consulta_usa_spatie_master(): void
    {
        [$bancaMia, $grupoMio, $taquillaMia] = $this->crearCadena('CM');
        [$bancaAjena, $grupoAjeno, $taquillaAjena] = $this->crearCadena('CA');

        $master = User::factory()->withRole('master')->create();
        Banca::whereKey($bancaMia->id)->update(['master_id' => $master->id]);

        $juego = $this->crearJuego();
        // Suma cero por cadena (10 + 20 + 100) para que existan las 3 filas.
        foreach ([[$bancaMia, $grupoMio, $taquillaMia], [$bancaAjena, $grupoAjeno, $taquillaAjena]] as [$banca, $grupo, $taquilla]) {
            $this->limite($juego->id, $banca->id, null, null, 10);
            $this->limite($juego->id, $banca->id, $grupo->id, null, 20);
            $this->limite($juego->id, $banca->id, $grupo->id, $taquilla->id, 100);
        }
        $this->apuesta($juego->id, $taquillaMia->id, 100, '2026-09-15 10:00:00');
        $this->apuesta($juego->id, $taquillaAjena->id, 100, '2026-09-15 11:00:00');

        $response = $this->actingAs($master, 'sanctum')
            ->getJson('/api/v1/comisiones/consulta?desde=2026-09-01&hasta=2026-09-30');

        $response->assertStatus(200);

        $filas = collect($response->json('data'));

        // Alcance master: solo su banca, nunca global
        $this->assertNotNull(
            $filas->first(fn ($f) => $f['nivel'] === 'taquilla' && $f['entidad_id'] === $taquillaMia->id),
            'El master debe ver su propia taquilla.'
        );
        $this->assertNull(
            $filas->first(fn ($f) => $f['nivel'] === 'taquilla' && $f['entidad_id'] === $taquillaAjena->id),
            'El master no debe ver taquillas ajenas.'
        );
        $this->assertNull(
            $filas->first(fn ($f) => $f['nivel'] === 'banca' && $f['entidad_id'] === $bancaAjena->id),
            'El master no debe ver bancas ajenas.'
        );
    }

    // ==================================================
    // Caso 2 — apuestas y cierres comparten el scope Spatie
    // ==================================================

    public function test_apuestas_y_cierres_comparten_scope(): void
    {
        [, , $taquillaA] = $this->crearCadena('AA');
        [$bancaB, $grupoB, $taquillaB] = $this->crearCadena('AB');
        $taquillaB->update(['mac_address' => '02:D1:00:00:00:02']);

        $user = User::factory()->withRole('taquilla')->create([
            'taquilla_id' => $taquillaB->id,
            'grupo_id' => $grupoB->id,
            'banca_id' => $bancaB->id,
        ]);

        $juego = $this->crearJuego();
        $this->apuesta($juego->id, $taquillaA->id, 100);
        $apuestaPropia = $this->apuesta($juego->id, $taquillaB->id, 200);

        $this->cierre($taquillaA, $user);
        $cierrePropio = $this->cierre($taquillaB, $user);

        $headers = ['X-Device-MAC' => '02:D1:00:00:00:02'];

        $apuestas = $this->withHeaders($headers)
            ->actingAs($user, 'sanctum')
            ->getJson('/api/v1/apuestas');

        $apuestas->assertStatus(200);
        $taquillasApuestas = collect($apuestas->json('data.data'))
            ->pluck('taquilla_id')
            ->unique()
            ->values()
            ->all();
        $this->assertSame([$taquillaB->id], $taquillasApuestas, 'Apuestas: solo la taquilla Spatie del usuario.');
        $this->assertSame([$apuestaPropia->id], collect($apuestas->json('data.data'))->pluck('id')->all());

        $cierres = $this->withHeaders($headers)
            ->actingAs($user, 'sanctum')
            ->getJson('/api/v1/cierre');

        $cierres->assertStatus(200);
        $this->assertSame(
            [$cierrePropio->id],
            collect($cierres->json('data'))->pluck('id')->all(),
            'Cierres: mismo scope que apuestas (solo la taquilla Spatie del usuario).'
        );
    }

    // ==================================================
    // Caso 3 — reportes: alcance del rol Spatie taquilla
    // ==================================================

    public function test_reportes_usan_spatie_taquilla(): void
    {
        [$bancaMia, $grupoMio, $taquillaMia] = $this->crearCadena('RM');
        [, , $taquillaAjena] = $this->crearCadena('RA');
        $taquillaMia->update(['mac_address' => '02:D1:00:00:00:03']);

        $user = User::factory()->withRole('taquilla')->create([
            'taquilla_id' => $taquillaMia->id,
            'grupo_id' => $grupoMio->id,
            'banca_id' => $bancaMia->id,
        ]);

        $juego = $this->crearJuego();
        $this->apuesta($juego->id, $taquillaMia->id, 2000, now()->subDay()->toDateTimeString());
        $this->apuesta($juego->id, $taquillaAjena->id, 5000, now()->subDay()->toDateTimeString());

        $response = $this->withHeaders(['X-Device-MAC' => '02:D1:00:00:00:03'])
            ->actingAs($user, 'sanctum')
            ->getJson('/api/v1/reportes/ventas-totales');

        $response->assertStatus(200);
        $this->assertEquals(
            2000,
            collect($response->json('data'))->sum('Venta'),
            'Solo debe ver las ventas de su taquilla: el rol Spatie no otorga alcance global.'
        );
    }

    // ==================================================
    // Caso 4 — VerifyMac exige MAC solo al rol Spatie taquilla
    // ==================================================

    public function test_verify_mac_usa_spatie_taquilla(): void
    {
        [, , $taquilla] = $this->crearCadena('VM');
        $taquilla->update(['mac_address' => '02:D1:00:00:00:04']);

        $taquillero = User::factory()->withRole('taquilla')->create([
            'taquilla_id' => $taquilla->id,
        ]);

        // Spatie taquilla: sin headers de dispositivo → 403
        $this->actingAs($taquillero, 'sanctum')
            ->getJson('/api/v1/juegos')
            ->assertStatus(403);

        // Spatie taquilla con MAC válida → 200
        $this->withHeaders(['X-Device-MAC' => '02:D1:00:00:00:04'])
            ->actingAs($taquillero, 'sanctum')
            ->getJson('/api/v1/juegos')
            ->assertStatus(200);

        // Spatie master → no exige MAC
        $master = User::factory()->withRole('master')->create();

        $this->actingAs($master, 'sanctum')
            ->getJson('/api/v1/juegos')
            ->assertStatus(200);
    }

    // ==================================================
    // Caso 5 — usuario sin rol Spatie: fail-closed
    // ==================================================

    public function test_usuario_sin_rol_spatie_recibe_403(): void
    {
        $sinRol = User::factory()->create(['active' => true]);

        $this->actingAs($sinRol, 'sanctum')
            ->getJson('/api/v1/users')
            ->assertStatus(403);

        $this->actingAs($sinRol, 'sanctum')
            ->getJson('/api/v1/reportes/ventas-totales')
            ->assertStatus(403);
    }

    // ==================================================
    // Query oculta — clave de cierre resuelve la cadena por Spatie
    // ==================================================

    public function test_clave_cierre_usa_spatie_en_la_cadena(): void
    {
        [$banca, , $taquilla] = $this->crearCadena('CC');

        $bancaUser = User::factory()->withRole('banca')->create([
            'banca_id' => $banca->id,
        ]);
        $bancaUser->update(['clave_cierre' => Hash::make('123456')]);

        app(CierreService::class)->validarClaveCierre($taquilla->id, '123456');

        $this->addToAssertionCount(1);
    }

    // ==================================================
    // Login — el rol efectivo sale de Spatie
    // ==================================================

    public function test_login_taquilla_usa_spatie_rol(): void
    {
        [$banca, $grupo, $taquilla] = $this->crearCadena('LG');
        $taquilla->update([
            'mac_address' => '02:D1:00:00:00:06',
            'device_fingerprint' => 'fd-divergencia-06',
        ]);

        $user = User::factory()->withRole('taquilla')->create([
            'email' => 'divergencia-login@test.com',
            'password' => bcrypt('password'),
            'taquilla_id' => $taquilla->id,
            'grupo_id' => $grupo->id,
            'banca_id' => $banca->id,
        ]);

        $response = $this->withHeaders(['X-Device-Fingerprint' => 'fd-divergencia-06'])
            ->postJson('/api/v1/login', [
                'email' => 'divergencia-login@test.com',
                'password' => 'password',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('role', 'taquilla')
            ->assertJsonPath('user.role', 'taquilla');
    }

    public function test_login_sin_rol_spatie_recibe_403(): void
    {
        $sinRol = User::factory()->create([
            'email' => 'divergencia-sin-rol@test.com',
            'password' => bcrypt('password'),
        ]);

        $this->withHeaders(['X-Panel' => 'true'])
            ->postJson('/api/v1/login', [
                'email' => 'divergencia-sin-rol@test.com',
                'password' => 'password',
            ])
            ->assertStatus(403);
    }
}
