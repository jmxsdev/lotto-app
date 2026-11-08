<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Banca;
use App\Models\Grupo;
use App\Models\Taquilla;
use App\Models\User;
use App\Services\CierreService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
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

    private static bool $failAudit = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        AuditEvent::creating(static function (): void {
            if (self::$failAudit) {
                throw new RuntimeException('forced audit insert failure');
            }
        });
    }

    protected function tearDown(): void
    {
        self::$failAudit = false;

        parent::tearDown();
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

    private function auditEvent(string $action): AuditEvent
    {
        return AuditEvent::query()->where('action', $action)->latest('id')->firstOrFail();
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
        $this->assertDatabaseMissing('audit_events', ['action' => 'clave_cierre.updated']);
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
    // Auditoría — clave_cierre.updated (WU-3b)
    // ==================================================

    public function test_primera_configuracion_emite_clave_cierre_updated_sin_exponer_la_clave()
    {
        $actor = $this->bancaUser();

        $this->actingAs($actor, 'sanctum')
            ->putJson('/api/v1/usuarios/clave-cierre', ['clave_nueva' => '123456'])
            ->assertStatus(200);

        $actor->refresh();
        $event = $this->auditEvent('clave_cierre.updated');
        $attributes = (string) json_encode($event->getAttributes());

        $this->assertSame('user', $event->actor_type);
        $this->assertSame($actor->id, $event->actor_id);
        $this->assertSame('b***@lotto.com', $event->actor_label);
        $this->assertNotSame($actor->email, $event->actor_label);
        $this->assertSame(['banca'], $event->actor_roles);
        $this->assertSame('configuracion', $event->entity_type);
        $this->assertSame($actor->id, $event->entity_id);

        // Scope derivado de la cadena del usuario objetivo.
        $this->assertSame($actor->banca_id, $event->banca_id);
        $this->assertNull($event->grupo_id);
        $this->assertNull($event->agencia_id);
        $this->assertNull($event->taquilla_id);

        // El evento solo declara que hubo cambio; sin before/after.
        $this->assertTrue($event->context['changed']);
        $this->assertNull($event->before);
        $this->assertNull($event->after);

        // Nunca la clave, su hash ni los campos del formulario.
        $this->assertArrayNotHasKey('clave_cierre', $event->getAttributes());
        $this->assertStringNotContainsString('clave_nueva', $attributes);
        $this->assertStringNotContainsString('clave_actual', $attributes);
        $this->assertStringNotContainsString('123456', $attributes);
        $this->assertStringNotContainsString($actor->clave_cierre, $attributes);
    }

    public function test_cambio_emite_clave_cierre_updated_con_motivo_sin_exponer_la_clave()
    {
        $actor = $this->bancaUser();
        $this->configurarClave($actor, '123456');

        $this->actingAs($actor, 'sanctum')
            ->putJson('/api/v1/usuarios/clave-cierre', [
                'clave_actual' => '123456',
                'clave_nueva' => '654321',
                'reason_code' => 'security_incident',
                'reason_text' => 'Rotación trimestral de la clave',
            ])
            ->assertStatus(200);

        $actor->refresh();
        $event = $this->auditEvent('clave_cierre.updated');
        $attributes = (string) json_encode($event->getAttributes());

        $this->assertSame($actor->id, $event->actor_id);
        $this->assertSame('configuracion', $event->entity_type);
        $this->assertSame($actor->id, $event->entity_id);
        $this->assertTrue($event->context['changed']);
        $this->assertSame('security_incident', $event->reason_code);
        $this->assertSame('Rotación trimestral de la clave', $event->reason_text);
        $this->assertNull($event->before);
        $this->assertNull($event->after);

        $this->assertStringNotContainsString('123456', $attributes);
        $this->assertStringNotContainsString('654321', $attributes);
        $this->assertStringNotContainsString($actor->clave_cierre, $attributes);
    }

    public function test_cambio_hace_rollback_si_falla_la_auditoria()
    {
        $actor = $this->bancaUser();
        $this->configurarClave($actor, '123456');

        $hashAnterior = $actor->fresh()->clave_cierre;

        self::$failAudit = true;

        $response = $this->actingAs($actor, 'sanctum')
            ->putJson('/api/v1/usuarios/clave-cierre', [
                'clave_actual' => '123456',
                'clave_nueva' => '654321',
            ]);

        $response->assertStatus(500);

        $actor->refresh();

        $this->assertSame($hashAnterior, $actor->clave_cierre, 'El fallo de auditoría debe revertir la clave.');
        $this->assertTrue(Hash::check('123456', $actor->clave_cierre));
        $this->assertFalse(Hash::check('654321', $actor->clave_cierre));
        $this->assertDatabaseMissing('audit_events', ['action' => 'clave_cierre.updated']);
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
