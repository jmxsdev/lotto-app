<?php

namespace Tests\Feature;

use App\Models\Banca;
use App\Models\Grupo;
use App\Models\Taquilla;
use App\Models\User;
use App\Services\CierreService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * PR 12 — clave de cierre (WU2).
 *
 * GET/PUT /api/v1/usuarios/clave-cierre son self-service (roles
 * super_master|master|banca): el hash bcrypt de la clave nunca se serializa
 * ni se guarda en claro; el cambio exige la clave actual; el formato es un
 * PIN de 4 a 8 dígitos. CierreService::validarClaveCierre() valida la clave
 * contra la cadena jerárquica de la taquilla (banca del grupo, master de esa
 * banca y todos los super_master).
 */
class ClaveCierreTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    // ==================================================
    // Helpers (mismos patrones que CierreCajaTest)
    // ==================================================

    private function superUser(): User
    {
        $super = User::where('email', 'super@lotto.com')->first();
        $super->assignRole('super_master');

        return $super;
    }

    private function masterUser(): User
    {
        $master = User::where('email', 'master@lotto.com')->first();
        $master->assignRole('master');
        // El master administra la banca sembrada (cadena de la taquilla TT001)
        Banca::where('code', 'BT001')->update(['master_id' => $master->id]);

        return $master;
    }

    private function bancaUser(): User
    {
        $user = User::where('email', 'banca@lotto.com')->first();
        $user->assignRole('banca');

        return $user;
    }

    private function grupoUser(): User
    {
        $user = User::where('email', 'grupo@lotto.com')->first();
        $user->assignRole('grupo');

        return $user;
    }

    private function taquillaUser(): User
    {
        $user = User::where('email', 'taquilla@lotto.com')->first();
        $user->assignRole('taquilla');

        return $user;
    }

    private function taquillaSeeded(): Taquilla
    {
        return Taquilla::where('code', 'TT001')->first();
    }

    private function configurarClave(User $user, string $clave): void
    {
        $user->update(['clave_cierre' => Hash::make($clave)]);
    }

    // ==================================================
    // PUT — primera configuración (hash, nunca texto plano)
    // ==================================================

    public function test_primera_configuracion_guarda_hash_y_nunca_texto_plano()
    {
        $user = $this->bancaUser();

        $response = $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/usuarios/clave-cierre', ['clave_nueva' => '123456']);

        $response->assertStatus(200)
            ->assertExactJson(['clave_configurada' => true]);

        $user->refresh();

        $this->assertNotNull($user->clave_cierre);
        $this->assertNotSame('123456', $user->clave_cierre, 'La clave no debe persistir en texto plano.');
        $this->assertTrue(Hash::check('123456', $user->clave_cierre), 'Debe persistir un hash bcrypt verificable.');
    }

    public function test_hash_no_se_serializa_en_las_respuestas()
    {
        $user = $this->bancaUser();
        $this->configurarClave($user, '123456');

        // GET /user (auth:sanctum): el payload no incluye el hash
        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/user')
            ->assertStatus(200)
            ->assertJsonMissingPath('clave_cierre');

        // GET /users (super_master): ningún usuario del listado expone el hash
        $response = $this->actingAs($this->superUser(), 'sanctum')
            ->getJson('/api/v1/users');

        $response->assertStatus(200);

        $usuarios = $response->json();
        $this->assertIsArray($usuarios, 'El listado de usuarios debe ser un arreglo.');
        $this->assertNotEmpty($usuarios, 'El listado debe contener usuarios para verificar la serialización.');
        foreach ($usuarios as $usuario) {
            $this->assertArrayNotHasKey('clave_cierre', $usuario, 'clave_cierre no debe serializarse en el listado.');
        }
    }

    // ==================================================
    // PUT — cambio de clave (exige clave_actual)
    // ==================================================

    public function test_cambio_con_clave_actual_correcta_actualiza_la_clave()
    {
        $user = $this->bancaUser();
        $this->configurarClave($user, '123456');

        $response = $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/usuarios/clave-cierre', [
                'clave_actual' => '123456',
                'clave_nueva' => '654321',
            ]);

        $response->assertStatus(200)
            ->assertExactJson(['clave_configurada' => true]);

        $user->refresh();

        $this->assertTrue(Hash::check('654321', $user->clave_cierre), 'La nueva clave debe quedar vigente.');
        $this->assertFalse(Hash::check('123456', $user->clave_cierre), 'La clave anterior debe quedar invalidada.');
    }

    public function test_cambio_con_clave_actual_incorrecta_responde_422_y_no_cambia()
    {
        $user = $this->bancaUser();
        $this->configurarClave($user, '123456');

        $response = $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/usuarios/clave-cierre', [
                'clave_actual' => '999999',
                'clave_nueva' => '654321',
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'La clave actual no coincide.');

        $user->refresh();

        $this->assertTrue(Hash::check('123456', $user->clave_cierre), 'La clave debe permanecer intacta.');
    }

    public function test_cambio_sin_clave_actual_responde_422()
    {
        $user = $this->bancaUser();
        $this->configurarClave($user, '123456');

        $response = $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/usuarios/clave-cierre', ['clave_nueva' => '654321']);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('clave_actual');
    }

    // ==================================================
    // PUT — formato del PIN (4 a 8 dígitos)
    // ==================================================

    public function test_formato_invalido_responde_422()
    {
        $user = $this->bancaUser();

        foreach (['123', '123456789', 'abcdef', '12ab56'] as $claveInvalida) {
            $response = $this->actingAs($user, 'sanctum')
                ->putJson('/api/v1/usuarios/clave-cierre', ['clave_nueva' => $claveInvalida]);

            $response->assertStatus(422)
                ->assertJsonValidationErrors('clave_nueva');
        }

        $user->refresh();
        $this->assertNull($user->clave_cierre, 'Ninguna clave inválida debe persistirse.');
    }

    public function test_pin_de_4_y_8_digitos_se_acepta()
    {
        // 4 dígitos (límite inferior) con un usuario
        $this->actingAs($this->bancaUser(), 'sanctum')
            ->putJson('/api/v1/usuarios/clave-cierre', ['clave_nueva' => '1111'])
            ->assertStatus(200)
            ->assertExactJson(['clave_configurada' => true]);

        // 8 dígitos (límite superior) con otro usuario elegible
        $master = $this->masterUser();

        $this->actingAs($master, 'sanctum')
            ->putJson('/api/v1/usuarios/clave-cierre', ['clave_nueva' => '12345678'])
            ->assertStatus(200)
            ->assertExactJson(['clave_configurada' => true]);

        $master->refresh();
        $this->assertTrue(Hash::check('12345678', $master->clave_cierre));
    }

    // ==================================================
    // GET — estado booleano (nunca el hash)
    // ==================================================

    public function test_get_estado_booleano_sin_exponer_el_hash()
    {
        $user = $this->bancaUser();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/usuarios/clave-cierre')
            ->assertStatus(200)
            ->assertExactJson(['clave_configurada' => false]);

        $this->configurarClave($user, '123456');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/usuarios/clave-cierre')
            ->assertStatus(200)
            ->assertExactJson(['clave_configurada' => true])
            ->assertJsonMissingPath('clave_cierre');
    }

    // ==================================================
    // Roles no elegibles → 403 (middleware de rol)
    // ==================================================

    public function test_endpoint_restringe_roles_no_elegibles()
    {
        foreach ([$this->taquillaUser(), $this->grupoUser()] as $rolNoElegible) {
            $this->actingAs($rolNoElegible, 'sanctum')
                ->getJson('/api/v1/usuarios/clave-cierre')
                ->assertStatus(403);

            $this->actingAs($rolNoElegible, 'sanctum')
                ->putJson('/api/v1/usuarios/clave-cierre', ['clave_nueva' => '123456'])
                ->assertStatus(403);
        }
    }

    // ==================================================
    // Self-service: el PUT solo afecta al autenticado
    // ==================================================

    public function test_self_service_solo_afecta_al_autenticado()
    {
        $banca = $this->bancaUser();
        $master = $this->masterUser();

        $this->actingAs($banca, 'sanctum')
            ->putJson('/api/v1/usuarios/clave-cierre', ['clave_nueva' => '111111'])
            ->assertStatus(200);

        $banca->refresh();
        $master->refresh();

        $this->assertTrue(Hash::check('111111', $banca->clave_cierre));
        $this->assertNull($master->clave_cierre, 'El PUT de un usuario no debe alterar la clave de otro.');
    }

    // ==================================================
    // CierreService::validarClaveCierre — cadena de la taquilla
    // ==================================================

    private function servicio(): CierreService
    {
        return app(CierreService::class);
    }

    public function test_validar_clave_acepta_clave_de_la_banca_de_la_taquilla()
    {
        $taquilla = $this->taquillaSeeded();
        $this->configurarClave($this->bancaUser(), '123456');

        $this->servicio()->validarClaveCierre($taquilla->id, '123456');
        $this->addToAssertionCount(1);
    }

    public function test_validar_clave_acepta_clave_del_master_de_la_banca()
    {
        $taquilla = $this->taquillaSeeded();
        $this->configurarClave($this->masterUser(), '654321');

        $this->servicio()->validarClaveCierre($taquilla->id, '654321');
        $this->addToAssertionCount(1);
    }

    public function test_validar_clave_acepta_clave_de_super_master()
    {
        $taquilla = $this->taquillaSeeded();
        $this->configurarClave($this->superUser(), '1111');

        $this->servicio()->validarClaveCierre($taquilla->id, '1111');
        $this->addToAssertionCount(1);
    }

    public function test_validar_clave_rechaza_clave_incorrecta()
    {
        $taquilla = $this->taquillaSeeded();
        $this->configurarClave($this->bancaUser(), '123456');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Clave de cierre incorrecta.');

        $this->servicio()->validarClaveCierre($taquilla->id, '999999');
    }

    public function test_validar_clave_sin_candidatos_con_clave_falla()
    {
        $taquilla = $this->taquillaSeeded();
        // Ningún usuario de la cadena (banca BT001, master, super_master)
        // tiene clave configurada.

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No hay una clave de cierre configurada para esta taquilla.');

        $this->servicio()->validarClaveCierre($taquilla->id, '123456');
    }

    public function test_validar_clave_rechaza_clave_de_otra_banca()
    {
        $taquilla = $this->taquillaSeeded();
        $this->configurarClave($this->bancaUser(), '123456');

        // Banca ajena: otra banca, otro grupo y una taquilla fuera de la cadena
        $otraBanca = Banca::create(['name' => 'Banca Ajena', 'code' => 'BT099', 'created_by' => $this->superUser()->id]);
        $otroGrupo = Grupo::create(['name' => 'Grupo Ajeno', 'code' => 'GT099', 'banca_id' => $otraBanca->id, 'created_by' => $this->superUser()->id]);
        $taquillaAjena = Taquilla::create([
            'name' => 'Taquilla Ajena',
            'code' => 'TT099',
            'grupo_id' => $otroGrupo->id,
            'activation_code' => 'TT099-CODE',
            'active' => true,
            'created_by' => $this->superUser()->id,
        ]);

        $bancaAjena = User::factory()->create([
            'role' => 'banca',
            'banca_id' => $otraBanca->id,
        ]);
        $bancaAjena->assignRole('banca');
        $this->configurarClave($bancaAjena, '555555');

        // La clave de la banca ajena no pertenece a la cadena de la taquilla TT001
        try {
            $this->servicio()->validarClaveCierre($taquilla->id, '555555');
            $this->fail('La clave de otra banca no debe validar para esta taquilla.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Clave de cierre incorrecta.', $e->getMessage());
        }

        // Control: la clave de la banca propia sí valida (mismo llamado)
        $this->servicio()->validarClaveCierre($taquilla->id, '123456');
        $this->addToAssertionCount(1);

        // Sanidad: la cadena de la taquilla ajena es distinta (banca BT099)
        $this->assertSame($taquillaAjena->grupo->banca_id, $otraBanca->id);
    }
}
