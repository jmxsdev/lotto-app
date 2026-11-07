<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Banca;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Contrato del export CSV de auditoría (WU-9b): cabecera exacta, BOM,
 * scope super_master/master, 403 sin `export_audit`, redacción, escape de
 * fórmulas, evento `audit.exported`, tope `max_rows` y filtros compartidos.
 */
class AuditoriaExportTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Columnas exactas del CSV (design §7.5).
     *
     * @var array<int, string>
     */
    private const COLUMNS = [
        'occurred_at',
        'event_uuid',
        'schema_version',
        'action',
        'actor_type',
        'actor_id',
        'actor_label',
        'actor_roles',
        'entity_type',
        'entity_id',
        'banca_id',
        'grupo_id',
        'agencia_id',
        'taquilla_id',
        'reason_code',
        'reason_text',
        'before',
        'after',
        'context',
        'ip',
        'user_agent',
        'request_id',
        'job_id',
        'hash',
        'prev_hash',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
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

    private function exportContent(User $actor, string $query = ''): string
    {
        $url = '/api/v1/audit-events/export'.($query !== '' ? '?'.$query : '');

        $response = $this->actingAs($actor, 'sanctum')->get($url);

        $response->assertOk();

        return $response->streamedContent();
    }

    /**
     * @return array<int, array<int, string>>
     */
    private function csvRows(string $content): array
    {
        $this->assertStringStartsWith("\xEF\xBB\xBF", $content);

        $lines = preg_split('/\r\n|\n|\r/', trim(substr($content, 3))) ?: [];

        return array_map(
            static fn (string $line): array => str_getcsv($line, ',', '"', ''),
            $lines,
        );
    }

    public function test_export_devuelve_csv_con_bom_y_cabecera_exacta(): void
    {
        $super = $this->userWithRole('super_master');
        $this->event(['action' => 'user.deleted', 'reason_code' => 'other']);

        $response = $this->actingAs($super, 'sanctum')->get('/api/v1/audit-events/export');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $this->assertMatchesRegularExpression(
            '/attachment;.*audit-events-\d{8}-\d{6}\.csv/',
            (string) $response->headers->get('Content-Disposition'),
        );

        $content = $response->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $content);

        $rows = $this->csvRows($content);

        $this->assertCount(2, $rows);
        $this->assertSame(self::COLUMNS, $rows[0]);
        $this->assertSame('user.deleted', $rows[1][3]);
    }

    public function test_master_solo_exporta_sus_bancas_y_super_master_todo(): void
    {
        $master = $this->userWithRole('master');
        $propia = Banca::factory()->create(['master_id' => $master->id]);
        $ajena = Banca::factory()->create();

        $eventPropio = $this->event(['banca_id' => $propia->id]);
        $eventAjeno = $this->event(['banca_id' => $ajena->id]);
        $eventGlobal = $this->event(['action' => 'exchange_rate.updated', 'banca_id' => null]);

        $masterCsv = $this->exportContent($master);

        $this->assertStringContainsString($eventPropio->event_uuid, $masterCsv);
        $this->assertStringNotContainsString($eventAjeno->event_uuid, $masterCsv);
        $this->assertStringNotContainsString($eventGlobal->event_uuid, $masterCsv);

        $super = $this->userWithRole('super_master');
        $superCsv = $this->exportContent($super);

        $this->assertStringContainsString($eventPropio->event_uuid, $superCsv);
        $this->assertStringContainsString($eventAjeno->event_uuid, $superCsv);
        $this->assertStringContainsString($eventGlobal->event_uuid, $superCsv);
    }

    public function test_rol_sin_export_audit_recibe_403(): void
    {
        $banca = $this->userWithRole('banca');

        $this->actingAs($banca, 'sanctum')
            ->get('/api/v1/audit-events/export')
            ->assertStatus(403);
    }

    public function test_export_redacta_secretos_igual_que_la_lectura(): void
    {
        $super = $this->userWithRole('super_master');

        $this->event([
            'before' => ['password' => 'super-secret', 'email' => 'victim@test.com'],
            'after' => ['mac_address' => 'AA:BB:CC:DD:EE:FF', 'activation_code' => '12345678'],
            'context' => [
                'device_fingerprint' => 'raw-fingerprint',
                'password' => 'another-secret',
                'route' => 'PUT /api/v1/users/{user}',
            ],
        ]);

        $csv = $this->exportContent($super);

        $this->assertStringNotContainsString('super-secret', $csv);
        $this->assertStringNotContainsString('another-secret', $csv);
        $this->assertStringNotContainsString('raw-fingerprint', $csv);
        $this->assertStringNotContainsString('AA:BB:CC:DD:EE:FF', $csv);
        $this->assertStringNotContainsString('12345678', $csv);

        $this->assertStringContainsString('[REDACTED]', $csv);
        $this->assertStringContainsString('AA:BB:CC:**:**:**', $csv);
        $this->assertStringContainsString('****5678', $csv);
    }

    public function test_export_escapa_formulas_en_celdas_de_texto(): void
    {
        $super = $this->userWithRole('super_master');

        $this->event([
            'actor_label' => '=SUM(A1)',
            'reason_text' => '+CMD',
            'ip' => '@evil',
            'user_agent' => '-1+2',
        ]);

        $rows = $this->csvRows($this->exportContent($super));
        $row = array_combine($rows[0], $rows[1]);

        $this->assertSame("'=SUM(A1)", $row['actor_label']);
        $this->assertSame("'+CMD", $row['reason_text']);
        $this->assertSame("'@evil", $row['ip']);
        $this->assertSame("'-1+2", $row['user_agent']);
    }

    public function test_export_registra_audit_exported_p1(): void
    {
        $super = $this->userWithRole('super_master');
        $this->event();
        $this->event(['action' => 'banca.updated']);

        $this->exportContent($super);

        $audit = AuditEvent::query()->where('action', 'audit.exported')->sole();

        $this->assertSame('user', $audit->actor_type);
        $this->assertSame($super->id, $audit->actor_id);
        $this->assertSame('audit', $audit->entity_type);
        $this->assertSame('unspecified', $audit->reason_code);

        $context = $audit->context;

        $this->assertSame([], $context['filters']);
        $this->assertSame(2, $context['count']);
        $this->assertSame('csv', $context['format']);
        $this->assertSame('completed', $context['status']);

        $encoded = (string) json_encode($context);
        $this->assertStringNotContainsString('password', $encoded);
        $this->assertStringNotContainsString('token', $encoded);
    }

    public function test_export_supera_max_rows_devuelve_422_sin_audit_exported(): void
    {
        $super = $this->userWithRole('super_master');
        config(['audit.export.max_rows' => 1]);

        $this->event();
        $this->event();

        $response = $this->actingAs($super, 'sanctum')->getJson('/api/v1/audit-events/export');

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('filters');

        $this->assertDatabaseMissing('audit_events', ['action' => 'audit.exported']);
    }

    public function test_export_respeta_filtros_de_accion_dominio_y_fechas(): void
    {
        $super = $this->userWithRole('super_master');

        $this->actingAs($super, 'sanctum')
            ->getJson('/api/v1/audit-events/export?foo=bar')
            ->assertStatus(422);
        $this->assertDatabaseMissing('audit_events', ['action' => 'audit.exported']);

        $objetivo = $this->event([
            'action' => 'user.deleted',
            'occurred_at' => CarbonImmutable::parse('2020-01-15 12:00:00', 'UTC'),
        ]);
        $otro = $this->event([
            'action' => 'banca.updated',
            'occurred_at' => CarbonImmutable::parse('2020-01-14 12:00:00', 'UTC'),
        ]);

        $porAccion = $this->exportContent($super, 'action=user.deleted');
        $this->assertStringContainsString($objetivo->event_uuid, $porAccion);
        $this->assertStringNotContainsString($otro->event_uuid, $porAccion);

        $porDominio = $this->exportContent($super, 'domain=user');
        $this->assertStringContainsString($objetivo->event_uuid, $porDominio);
        $this->assertStringNotContainsString($otro->event_uuid, $porDominio);

        $porFecha = $this->exportContent($super, 'from=2020-01-15&to=2020-01-15');
        $this->assertStringContainsString($objetivo->event_uuid, $porFecha);
        $this->assertStringNotContainsString($otro->event_uuid, $porFecha);

        $interseccion = $this->exportContent($super, 'action=user.deleted&domain=banca');
        $this->assertStringNotContainsString($objetivo->event_uuid, $interseccion);
    }
}
