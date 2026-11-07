<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Banca;
use App\Models\Taquilla;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class AuditoriaAuthTest extends TestCase
{
    use RefreshDatabase;

    private static bool $failAudit = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

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

    private function userWithRole(string $role, array $attributes = []): User
    {
        return User::factory()->withRole($role)->create(array_merge([
            'password' => bcrypt('password'),
        ], $attributes));
    }

    /**
     * Taquilla pre-activada con dispositivo registrado y su usuario.
     *
     * @return array{0: User, 1: Taquilla}
     */
    private function taquillaUser(array $overrides = []): array
    {
        $taquilla = Taquilla::factory()->create(array_merge([
            'active' => true,
            'mac_address' => 'AA:BB:CC:DD:EE:FF',
            'device_fingerprint' => 'fp-audit-001',
        ], $overrides));

        $user = $this->userWithRole('taquilla', [
            'email' => 'taquilla-audit@test.com',
            'taquilla_id' => $taquilla->id,
            'grupo_id' => $taquilla->grupo_id,
            'banca_id' => $taquilla->grupo->banca_id,
        ]);

        return [$user, $taquilla];
    }

    private function auditEvent(string $action): AuditEvent
    {
        return AuditEvent::query()->where('action', $action)->latest('id')->firstOrFail();
    }

    private function login(string $email, string $password = 'password', array $headers = [])
    {
        return $this->withHeaders($headers)->postJson('/api/v1/login', [
            'email' => $email,
            'password' => $password,
        ]);
    }

    public function test_login_exitoso_registra_actor_scope_y_token_sin_plaintext(): void
    {
        $user = $this->userWithRole('master', ['email' => 'master-audit@test.com']);

        $response = $this->login($user->email, 'password', ['X-Panel' => 'true']);

        $response->assertStatus(200);

        $plainTextToken = (string) $response->json('token');
        $tokenId = (int) DB::table('personal_access_tokens')
            ->where('tokenable_type', User::class)
            ->where('tokenable_id', $user->id)
            ->value('id');

        $event = $this->auditEvent('auth.login_succeeded');

        $this->assertSame('user', $event->actor_type);
        $this->assertSame($user->id, $event->actor_id);
        $this->assertSame($user->email, $event->actor_label);
        $this->assertSame(['master'], $event->actor_roles);
        $this->assertNull($event->banca_id);
        $this->assertNull($event->entity_type);
        $this->assertSame($tokenId, (int) $event->context['token_id']);
        $this->assertSame('panel', $event->context['client']);
        $this->assertSame('api', $event->context['source']);
        $this->assertStringNotContainsString($plainTextToken, (string) json_encode($event->getAttributes()));
    }

    public function test_login_con_credenciales_invalidas_registra_email_enmascarado(): void
    {
        $user = $this->userWithRole('master', ['email' => 'victima@test.com']);

        $response = $this->login($user->email, 'wrong-password', ['X-Panel' => 'true']);

        $response->assertStatus(401);

        $event = $this->auditEvent('auth.login_failed');
        $attributes = (string) json_encode($event->getAttributes());

        $this->assertSame('security_incident', $event->reason_code);
        $this->assertNull($event->actor_id);
        $this->assertNull($event->actor_label);
        $this->assertSame('bad_credentials', $event->context['failure_reason']);
        $this->assertSame('v***@test.com', $event->context['attempted_email']);
        $this->assertSame('panel', $event->context['client']);
        $this->assertSame('api', $event->context['source']);
        $this->assertStringNotContainsString('wrong-password', $attributes);
        $this->assertStringNotContainsString($user->password, $attributes);
    }

    public function test_login_usuario_inactivo_registra_inactive_user(): void
    {
        $user = $this->userWithRole('master', [
            'email' => 'inactivo@test.com',
            'active' => false,
        ]);

        $response = $this->login($user->email, 'password', ['X-Panel' => 'true']);

        $response->assertStatus(403)->assertJsonPath('message', 'Usuario inactivo');

        $event = $this->auditEvent('auth.login_failed');
        $this->assertSame('inactive_user', $event->context['failure_reason']);
        $this->assertSame('i***@test.com', $event->context['attempted_email']);
    }

    public function test_login_sin_rol_registra_no_role(): void
    {
        $user = User::factory()->create([
            'email' => 'sin-rol@test.com',
            'password' => bcrypt('password'),
        ]);

        $response = $this->login($user->email, 'password', ['X-Panel' => 'true']);

        $response->assertStatus(403)->assertJsonPath('message', 'Usuario sin rol asignado.');

        $event = $this->auditEvent('auth.login_failed');
        $this->assertSame('no_role', $event->context['failure_reason']);
    }

    public function test_login_panel_con_rol_taquilla_registra_panel_wrong_role(): void
    {
        [$user] = $this->taquillaUser();

        $response = $this->login($user->email, 'password', [
            'X-Panel' => 'true',
            'X-Device-Fingerprint' => 'fp-audit-001',
        ]);

        $response->assertStatus(403)->assertJsonPath('message', 'Las taquillas deben usar la app de escritorio.');

        $event = $this->auditEvent('auth.login_failed');
        $this->assertSame('panel_wrong_role', $event->context['failure_reason']);
    }

    public function test_login_sin_panel_para_rol_no_taquilla_registra_taquilla_required(): void
    {
        $user = $this->userWithRole('master', ['email' => 'master-sin-panel@test.com']);

        $response = $this->login($user->email);

        $response->assertStatus(403)->assertJsonPath('message', 'Use el panel de administración para acceder.');

        $event = $this->auditEvent('auth.login_failed');
        $this->assertSame('taquilla_required', $event->context['failure_reason']);
    }

    public function test_login_taquilla_con_fingerprint_incorrecto_registra_device_fingerprint_mismatch(): void
    {
        [$user] = $this->taquillaUser();

        $response = $this->login($user->email, 'password', [
            'X-Device-Fingerprint' => 'fp-otro-dispositivo',
        ]);

        $response->assertStatus(403)->assertJsonPath('message', 'Huella digital del dispositivo no coincide.');

        $event = $this->auditEvent('auth.login_failed');
        $this->assertSame('device_fingerprint_mismatch', $event->context['failure_reason']);
    }

    public function test_login_con_cadena_inactiva_registra_causa_y_mensaje(): void
    {
        $banca = Banca::factory()->create(['active' => false]);
        $user = $this->userWithRole('banca', [
            'email' => 'banca-pausada@test.com',
            'banca_id' => $banca->id,
        ]);

        $response = $this->login($user->email, 'password', ['X-Panel' => 'true']);

        $response->assertStatus(403)
            ->assertJsonPath('message', 'Tu cuenta está pausada porque su banca está desactivada.');

        $event = $this->auditEvent('auth.login_failed');
        $this->assertSame('chain_inactive', $event->context['failure_reason']);
        $this->assertSame('banca', $event->context['chain_cause']);
        $this->assertSame(
            'Tu cuenta está pausada porque su banca está desactivada.',
            $event->context['chain_message'],
        );
    }

    public function test_logout_registra_eventos_y_elimina_el_token_sin_plaintext(): void
    {
        $user = $this->userWithRole('master', ['email' => 'logout-audit@test.com']);

        $newToken = $user->createToken('api-test');
        $plainTextToken = $newToken->plainTextToken;
        $tokenId = (int) $newToken->accessToken->id;

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$plainTextToken,
        ])->postJson('/api/v1/logout');

        $response->assertStatus(200);

        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $tokenId]);

        $logout = $this->auditEvent('auth.logout');
        $revoked = $this->auditEvent('auth.token_revoked');

        foreach ([$logout, $revoked] as $event) {
            $this->assertSame($user->id, $event->actor_id);
            $this->assertSame($user->email, $event->actor_label);
            $this->assertSame(['master'], $event->actor_roles);
            $this->assertSame($tokenId, (int) $event->context['token_id']);
            $this->assertStringNotContainsString($plainTextToken, (string) json_encode($event->getAttributes()));
        }
    }

    public function test_login_hace_rollback_del_token_si_falla_la_auditoria(): void
    {
        $user = $this->userWithRole('master', ['email' => 'rollback-audit@test.com']);

        self::$failAudit = true;

        $response = $this->login($user->email, 'password', ['X-Panel' => 'true']);

        $response->assertStatus(500);

        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertSame(
            0,
            AuditEvent::where('action', '!=', 'permission.catalog_synced')->count(),
        );
    }
}
