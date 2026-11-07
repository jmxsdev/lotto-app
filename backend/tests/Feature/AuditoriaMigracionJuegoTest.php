<?php

namespace Tests\Feature;

use App\Exceptions\AuditAppendOnlyException;
use App\Models\Juego;
use App\Models\JuegoAuditoria;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * WU-12a — `juego_auditoria` → `audit_events` data migration.
 *
 * The migration must copy every legacy row with a deterministic UUID, map the
 * legacy `accion` to an `AuditAction`, preserve before/after and keep the
 * legacy context. Re-running it must not duplicate events.
 */
class AuditoriaMigracionJuegoTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @var array<string, string>
     */
    private const ACTION_MAP = [
        'activar' => 'game.toggled',
        'desactivar' => 'game.toggled',
        'actualizar' => 'game.updated',
        'premios' => 'game.prizes_updated',
    ];

    private User $user;

    private Juego $juego;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->user = User::where('email', 'super@lotto.com')->firstOrFail();
        $this->juego = Juego::where('slug', 'triple-zulia')->firstOrFail();
    }

    public function test_migra_las_filas_legacy_sin_perdida_y_es_idempotente(): void
    {
        $createdAt = '2026-10-01 13:45:00';

        $rows = [
            [
                'accion' => 'activar',
                'cambios' => ['before' => ['active' => true], 'after' => ['active' => false]],
            ],
            [
                'accion' => 'actualizar',
                'cambios' => ['before' => ['name' => 'A'], 'after' => ['name' => 'B']],
            ],
            [
                'accion' => 'premios',
                'cambios' => ['before' => ['base' => 500], 'after' => ['base' => 700]],
            ],
            [
                'accion' => 'desconocida',
                'cambios' => ['before' => null, 'after' => ['x' => 1]],
            ],
        ];

        $ids = [];

        foreach ($rows as $row) {
            $ids[] = DB::table('juego_auditoria')->insertGetId([
                'juego_id' => $this->juego->id,
                'user_id' => $this->user->id,
                'accion' => $row['accion'],
                'cambios' => json_encode($row['cambios']),
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);
        }

        $migration = require database_path('migrations/2026_11_05_000003_migrate_juego_auditoria_to_audit_events.php');
        $migration->up();

        $this->assertSame(
            count($rows),
            DB::table('audit_events')->where('action', '!=', 'permission.catalog_synced')->count(),
        );

        $expectedOccurredAt = CarbonImmutable::parse($createdAt, config('app.timezone'))
            ->utc()
            ->format('Y-m-d H:i:s');

        foreach ($rows as $index => $row) {
            $legacyId = $ids[$index];
            $event = DB::table('audit_events')
                ->where('event_uuid', sha1('juego_auditoria:'.$legacyId))
                ->first();

            $this->assertNotNull($event, 'Cada fila legacy debe tener su evento migrado.');
            $this->assertSame('user', $event->actor_type);
            $this->assertSame($this->user->id, (int) $event->actor_id);
            $this->assertNull($event->actor_label);
            $this->assertNull($event->actor_roles);
            $this->assertSame(self::ACTION_MAP[$row['accion']] ?? 'game.updated', $event->action);
            $this->assertSame('juego', $event->entity_type);
            $this->assertSame($this->juego->id, (int) $event->entity_id);
            $this->assertNull($event->banca_id);
            $this->assertNull($event->grupo_id);
            $this->assertNull($event->agencia_id);
            $this->assertNull($event->taquilla_id);
            $this->assertSame(1, (int) $event->schema_version);
            $this->assertSame($expectedOccurredAt, $event->occurred_at);
            $this->assertSame(
                $row['cambios']['before'],
                $event->before === null ? null : json_decode($event->before, true),
            );
            $this->assertSame(
                $row['cambios']['after'],
                $event->after === null ? null : json_decode($event->after, true),
            );

            $context = json_decode($event->context, true);
            $this->assertSame('juego_auditoria', $context['legacy_source']);
            $this->assertSame($legacyId, (int) $context['legacy_id']);
            $this->assertSame($row['accion'], $context['legacy_accion']);
        }

        // Re-running the migration must be a no-op (unique event_uuid).
        $migration->up();

        $this->assertSame(
            count($rows),
            DB::table('audit_events')->where('action', '!=', 'permission.catalog_synced')->count(),
        );
    }

    public function test_juego_auditoria_no_admite_escrituras_del_modelo(): void
    {
        try {
            JuegoAuditoria::create([
                'juego_id' => $this->juego->id,
                'user_id' => $this->user->id,
                'accion' => 'actualizar',
                'cambios' => ['before' => [], 'after' => []],
            ]);

            $this->fail('JuegoAuditoria debe rechazar el create.');
        } catch (AuditAppendOnlyException $exception) {
            $this->assertStringContainsString('read-only', $exception->getMessage());
        }
    }
}
