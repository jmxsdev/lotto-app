<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Banca;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Contrato de lectura de auditoría (WU-9a): cursor pagination, filtros,
 * alcance super_master/master, 403 para el resto, redacción de lectura y
 * permisos aditivos en login/GET /user.
 */
class AuditoriaLecturaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The permission catalog sync event emitted by the seeder is irrelevant
        // to the read contract; keep the fixture event list deterministic.
        config(['audit.enabled' => false]);
        $this->seed(RolesAndPermissionsSeeder::class);
        config(['audit.enabled' => true]);
    }

    private function userWithRole(string $role, array $attributes = []): User
    {
        return User::factory()->withRole($role)->create($attributes);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function event(array $overrides = []): AuditEvent
    {
        return AuditEvent::create(array_merge([
            'event_uuid' => (string) Str::uuid(),
            'schema_version' => 1,
            'occurred_at' => CarbonImmutable::parse('2026-11-05 12:00:00', 'UTC'),
            'actor_type' => 'user', 'actor_id' => null, 'actor_label' => null, 'actor_roles' => null,
            'action' => 'user.updated', 'entity_type' => 'user', 'entity_id' => null,
            'banca_id' => null, 'grupo_id' => null, 'agencia_id' => null, 'taquilla_id' => null,
            'before' => null, 'after' => null,
            'context' => ['route' => 'PUT /api/v1/users/{user}', 'method' => 'PUT', 'client' => 'panel'],
            'reason_code' => null, 'reason_text' => null,
            'ip' => '190.0.0.1', 'user_agent' => 'phpunit',
            'request_id' => null, 'job_id' => null, 'hash' => null, 'prev_hash' => null,
        ], $overrides));
    }

    /**
     * @return array<int, int>
     */
    private function filteredIds(User $actor, string $query): array
    {
        $response = $this->actingAs($actor, 'sanctum')
            ->getJson('/api/v1/audit-events?'.$query);

        $response->assertOk();

        return collect($response->json('data'))->pluck('id')->all();
    }

    public function test_super_master_ve_todas_las_bancas_y_los_eventos_globales(): void
    {
        $super = $this->userWithRole('super_master');
        $bancaA = Banca::factory()->create();
        $bancaB = Banca::factory()->create();

        $eventA = $this->event(['banca_id' => $bancaA->id]);
        $eventB = $this->event(['banca_id' => $bancaB->id]);
        $global = $this->event(['action' => 'exchange_rate.updated', 'banca_id' => null]);

        $response = $this->actingAs($super, 'sanctum')->getJson('/api/v1/audit-events');

        $response->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->all();
        sort($ids);

        $this->assertSame([$eventA->id, $eventB->id, $global->id], $ids);

        $row = $response->json('data.0');
        $this->assertSame('exchange_rate.updated', $row['action']);
        $this->assertSame('exchange_rate', $row['domain']);
        $this->assertSame('190.0.0.1', $row['ip']);
        $this->assertSame('2026-11-05T12:00:00Z', $row['occurred_at']);
    }

    public function test_master_ve_solo_los_eventos_de_sus_bancas(): void
    {
        $master = $this->userWithRole('master');
        $propia = Banca::factory()->create(['master_id' => $master->id]);
        $ajena = Banca::factory()->create();

        $eventPropio = $this->event(['banca_id' => $propia->id]);
        $this->event(['banca_id' => $ajena->id]);
        $this->event(['banca_id' => null]);

        $response = $this->actingAs($master, 'sanctum')->getJson('/api/v1/audit-events');

        $response->assertOk();
        $this->assertSame([$eventPropio->id], collect($response->json('data'))->pluck('id')->all());

        $sinBancas = $this->userWithRole('master');
        $vacio = $this->actingAs($sinBancas, 'sanctum')->getJson('/api/v1/audit-events');

        $vacio->assertOk();
        $this->assertSame([], $vacio->json('data'));
        $this->assertNull($vacio->json('next_cursor'));
    }

    #[DataProvider('rolesSinAcceso')]
    public function test_roles_sin_permiso_reciben_403(string $role): void
    {
        $user = $this->userWithRole($role);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/audit-events')
            ->assertStatus(403);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function rolesSinAcceso(): array
    {
        return [
            'banca' => ['banca'],
            'grupo' => ['grupo'],
            'agencia' => ['agencia'],
            'taquilla' => ['taquilla'],
        ];
    }

    public function test_cursor_pagina_en_orden_desc_con_desempate_por_id(): void
    {
        $super = $this->userWithRole('super_master');
        $empate = CarbonImmutable::parse('2026-11-05 12:00:00', 'UTC');

        $viejo = $this->event(['occurred_at' => CarbonImmutable::parse('2026-11-04 12:00:00', 'UTC')]);
        $empateA = $this->event(['occurred_at' => $empate]);
        $empateB = $this->event(['occurred_at' => $empate]);
        $nuevo = $this->event(['occurred_at' => CarbonImmutable::parse('2026-11-06 12:00:00', 'UTC')]);

        $primera = $this->actingAs($super, 'sanctum')
            ->getJson('/api/v1/audit-events?per_page=2');

        $primera->assertOk();
        $this->assertSame(
            [$nuevo->id, $empateB->id],
            collect($primera->json('data'))->pluck('id')->all(),
        );
        $this->assertNotNull($primera->json('next_cursor'));
        $this->assertNull($primera->json('prev_cursor'));

        $segunda = $this->actingAs($super, 'sanctum')
            ->getJson('/api/v1/audit-events?per_page=2&cursor='.urlencode((string) $primera->json('next_cursor')));

        $segunda->assertOk();
        $this->assertSame(
            [$empateA->id, $viejo->id],
            collect($segunda->json('data'))->pluck('id')->all(),
        );
        $this->assertNull($segunda->json('next_cursor'));
        $this->assertNotNull($segunda->json('prev_cursor'));

        $anterior = $this->actingAs($super, 'sanctum')
            ->getJson('/api/v1/audit-events?per_page=2&cursor='.urlencode((string) $segunda->json('prev_cursor')));

        $anterior->assertOk();
        $this->assertSame(
            [$nuevo->id, $empateB->id],
            collect($anterior->json('data'))->pluck('id')->all(),
        );
    }

    public function test_per_page_excedido_se_acota_a_100(): void
    {
        $super = $this->userWithRole('super_master');
        $base = CarbonImmutable::parse('2026-11-05 12:00:00', 'UTC');

        for ($i = 0; $i < 105; $i++) {
            $this->event(['occurred_at' => $base->addSeconds($i)]);
        }

        $response = $this->actingAs($super, 'sanctum')
            ->getJson('/api/v1/audit-events?per_page=500');

        $response->assertOk();
        $this->assertCount(100, $response->json('data'));
        $this->assertNotNull($response->json('next_cursor'));
    }

    public function test_cursor_invalido_y_clave_desconocida_devuelven_422(): void
    {
        $super = $this->userWithRole('super_master');
        $roto = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode('{"foo":"bar"}'));
        $sinParametros = str_replace(
            ['+', '/', '='],
            ['-', '_', ''],
            base64_encode((string) json_encode(['_pointsToNextItems' => true])),
        );

        foreach (['no-es-un-cursor', $roto, $sinParametros] as $cursor) {
            $this->actingAs($super, 'sanctum')
                ->getJson('/api/v1/audit-events?cursor='.urlencode($cursor))
                ->assertStatus(422);
        }

        $this->actingAs($super, 'sanctum')
            ->getJson('/api/v1/audit-events?foo=bar')
            ->assertStatus(422);
    }

    public function test_filtros_por_accion_dominio_entidad_actor_request_y_motivo(): void
    {
        $super = $this->userWithRole('super_master');

        $objetivo = $this->event([
            'action' => 'user.deleted',
            'entity_type' => 'user',
            'entity_id' => 42,
            'actor_id' => 7,
            'request_id' => 'req-user',
            'reason_code' => 'employee_offboarding',
        ]);

        $this->event([
            'action' => 'banca.updated',
            'entity_type' => 'banca',
            'entity_id' => 9,
            'actor_id' => 8,
            'request_id' => 'req-banca',
            'reason_code' => 'other',
        ]);

        $this->assertSame([$objetivo->id], $this->filteredIds($super, 'action=user.deleted'));
        $this->assertSame([$objetivo->id], $this->filteredIds($super, 'domain=user'));
        $this->assertSame([$objetivo->id], $this->filteredIds($super, 'entity_type=user&entity_id=42'));
        $this->assertSame([$objetivo->id], $this->filteredIds($super, 'actor_id=7'));
        $this->assertSame([$objetivo->id], $this->filteredIds($super, 'request_id=req-user'));
        $this->assertSame([$objetivo->id], $this->filteredIds($super, 'reason_code=employee_offboarding'));
        $this->assertSame([$objetivo->id], $this->filteredIds($super, 'action=user.deleted&domain=user'));
        $this->assertSame([], $this->filteredIds($super, 'action=user.deleted&domain=banca'));
    }

    public function test_filtros_de_fecha_interpretan_caracas_y_convierten_a_utc(): void
    {
        $super = $this->userWithRole('super_master');

        $dentro = $this->event(['occurred_at' => CarbonImmutable::parse('2026-11-05 12:00:00', 'UTC')]);
        $this->event(['occurred_at' => CarbonImmutable::parse('2026-11-05 03:00:00', 'UTC')]);
        $this->event(['occurred_at' => CarbonImmutable::parse('2026-11-06 12:00:00', 'UTC')]);

        $this->assertSame(
            [$dentro->id],
            $this->filteredIds($super, 'from=2026-11-05&to=2026-11-05'),
        );

        $this->assertSame(
            [$dentro->id],
            $this->filteredIds(
                $super,
                'from='.urlencode('2026-11-05T12:00:00Z').'&to='.urlencode('2026-11-05T12:00:00Z'),
            ),
        );
    }

    public function test_lectura_redacta_secretos_y_no_expone_al_usuario_completo(): void
    {
        $super = $this->userWithRole('super_master', ['email' => 'secret-super@test.com']);

        $this->event([
            'actor_id' => $super->id,
            'actor_label' => 'j***@lotto.test',
            'actor_roles' => ['super_master'],
            'before' => ['password' => 'super-secret', 'email' => 'victim@test.com'],
            'after' => ['mac_address' => 'AA:BB:CC:DD:EE:FF', 'activation_code' => '12345678'],
            'context' => [
                'device_fingerprint' => 'raw-fingerprint',
                'password' => 'another-secret',
                'route' => 'PUT /api/v1/users/{user}',
            ],
        ]);

        $response = $this->actingAs($super, 'sanctum')->getJson('/api/v1/audit-events');

        $response->assertOk();

        $row = $response->json('data.0');
        $encoded = (string) json_encode($row);

        $this->assertStringNotContainsString('super-secret', $encoded);
        $this->assertStringNotContainsString('another-secret', $encoded);
        $this->assertStringNotContainsString('raw-fingerprint', $encoded);
        $this->assertStringNotContainsString('AA:BB:CC:DD:EE:FF', $encoded);
        $this->assertStringNotContainsString('12345678', $encoded);

        $this->assertSame('[REDACTED]', $row['before']['password']);
        $this->assertSame('v***@test.com', $row['before']['email']);
        $this->assertSame('AA:BB:CC:**:**:**', $row['after']['mac_address']);
        $this->assertSame('****5678', $row['after']['activation_code']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $row['context']['device_fingerprint']);

        $this->assertArrayNotHasKey('user', $row);
        $this->assertSame(['type', 'id', 'label', 'roles'], array_keys($row['actor']));
        $this->assertSame(['super_master'], $row['actor']['roles']);
        $this->assertArrayNotHasKey('password', $row['actor']);
        $this->assertArrayNotHasKey('remember_token', $row['actor']);
    }

    public function test_login_y_user_exponen_los_permisos_de_auditoria(): void
    {
        $master = $this->userWithRole('master', [
            'email' => 'master-perm@test.com',
            'password' => bcrypt('password'),
        ]);
        $super = $this->userWithRole('super_master', ['email' => 'super-perm@test.com']);

        $login = $this->withHeaders(['X-Panel' => 'true'])->postJson('/api/v1/login', [
            'email' => 'master-perm@test.com',
            'password' => 'password',
        ]);

        $login->assertOk();
        $permisosLogin = $login->json('user.permissions');
        $this->assertIsArray($permisosLogin);
        $this->assertContains('view_audit', $permisosLogin);
        $this->assertContains('export_audit', $permisosLogin);

        $user = $this->actingAs($master, 'sanctum')->getJson('/api/v1/user');
        $user->assertOk();
        $this->assertContains('view_audit', $user->json('permissions'));
        $this->assertContains('export_audit', $user->json('permissions'));

        $superUser = $this->actingAs($super, 'sanctum')->getJson('/api/v1/user');
        $superUser->assertOk();
        $this->assertSame([], $superUser->json('permissions'));
    }
}
