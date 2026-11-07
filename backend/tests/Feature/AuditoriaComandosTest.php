<?php

namespace Tests\Feature;

use App\Models\Juego;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AuditoriaComandosTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        config(['audit.enabled' => true, 'audit.hash_chain' => false]);

        $this->user = User::factory()->create(['email' => 'legacy@lotto.test']);
    }

    public function test_logs_dry_run_no_inserta_y_no_emite_backfill_completed(): void
    {
        $this->logRow('user.role_changed', ['user_id' => $this->user->id], $this->user->id);
        $this->logRow('scrape_resultados');
        $this->logRow('accion_desconocida');

        $this->artisan('audit:backfill', ['--source' => 'logs'])->assertExitCode(0);

        $this->assertSame(0, DB::table('audit_events')->count());
        $this->assertSame(0, DB::table('audit_events')->where('action', 'backfill.completed')->count());
    }

    public function test_logs_apply_inserta_solo_los_mapeados_con_envelope_correcto(): void
    {
        $roleId = $this->logRow('user.role_changed', [
            'user_id' => $this->user->id,
            'before' => ['roles' => ['banca']],
            'after' => ['roles' => ['grupo']],
        ], $this->user->id, '2026-10-01 09:00:00');

        $cases = [
            ['activacion_taquilla', ['taquilla_id' => 77, 'exitoso' => true], 'taquilla.activation_succeeded', 'taquilla', 77, 'device'],
            ['activacion_taquilla', ['taquilla_id' => 78, 'exitoso' => false], 'taquilla.activation_failed', 'taquilla', 78, 'device'],
            ['activacion_taquilla', ['taquilla_id' => 79, 'mac_reassigned' => true], 'taquilla.mac_reassigned', 'taquilla', 79, 'device'],
            ['pago_premio', ['pago_id' => 501], 'pago.prize_paid', 'pago', 501, 'user'],
            ['ticket_anulado', ['ticket_id' => 601], 'ticket.voided', 'ticket', 601, 'user'],
            ['apuesta.anulada', ['apuesta_id' => 701], 'bet.voided', 'apuesta', 701, 'user'],
            ['auditoria.pago_legacy', ['apuesta_id' => 702], 'pago.legacy_reconciled', 'apuesta', 702, 'user'],
        ];

        $ids = [];

        foreach ($cases as $case) {
            $ids[] = $this->logRow($case[0], $case[1], $case[5] === 'device' ? null : $this->user->id);
        }

        $scrapeId = $this->logRow('scrape_resultados');
        $animalitosId = $this->logRow('scrape_animalitos');
        $unknownId = $this->logRow('accion_desconocida');

        $this->artisan('audit:backfill', ['--source' => 'logs', '--apply' => true])->assertExitCode(0);

        $role = $this->eventByUuid(sha1('logs:'.$roleId));

        $this->assertSame('user.role_changed', $role->action);
        $this->assertSame('user', $role->entity_type);
        $this->assertSame($this->user->id, (int) $role->entity_id);
        $this->assertSame('user', $role->actor_type);
        $this->assertSame($this->user->id, (int) $role->actor_id);
        $this->assertSame('l***@lotto.test', $role->actor_label);
        $this->assertNull($role->actor_roles);
        $this->assertSame(
            CarbonImmutable::parse('2026-10-01 09:00:00', config('app.timezone'))->utc()->format('Y-m-d H:i:s'),
            $role->occurred_at,
        );
        $this->assertSame('190.0.0.1', $role->ip);
        $this->assertSame('phpunit', $role->user_agent);
        $this->assertSame(['roles' => ['banca']], json_decode($role->before, true));
        $this->assertSame(['roles' => ['grupo']], json_decode($role->after, true));

        $context = json_decode($role->context, true);
        $this->assertTrue($context['backfill']);
        $this->assertSame('logs', $context['source']);
        $this->assertSame($roleId, $context['legacy_id']);
        $this->assertSame('user.role_changed', $context['legacy_action']);

        foreach ($cases as $index => $case) {
            $event = $this->eventByUuid(sha1('logs:'.$ids[$index]));

            $this->assertSame($case[2], $event->action);
            $this->assertSame($case[3], $event->entity_type);
            $this->assertSame($case[4], (int) $event->entity_id);
            $this->assertSame($case[5], $event->actor_type);
        }

        foreach ([$scrapeId, $animalitosId, $unknownId] as $excludedId) {
            $this->assertSame(0, DB::table('audit_events')->where('event_uuid', sha1('logs:'.$excludedId))->count());
        }

        $this->assertSame(8, DB::table('audit_events')->where('action', '!=', 'backfill.completed')->count());

        $completed = DB::table('audit_events')->where('action', 'backfill.completed')->sole();
        $completedContext = json_decode($completed->context, true);

        $this->assertSame('audit', $completed->entity_type);
        $this->assertSame('system', $completed->actor_type);
        $this->assertSame('logs', $completedContext['source']);
        $this->assertSame(11, $completedContext['scanned']);
        $this->assertSame(8, $completedContext['inserted']);
        $this->assertSame(3, $completedContext['skipped']);
        $this->assertFalse($completedContext['dry_run']);
    }

    public function test_logs_apply_es_idempotente(): void
    {
        $legacyId = $this->logRow('pago_premio', ['pago_id' => 1], $this->user->id);

        $this->artisan('audit:backfill', ['--source' => 'logs', '--apply' => true])->assertExitCode(0);
        $this->artisan('audit:backfill', ['--source' => 'logs', '--apply' => true])->assertExitCode(0);

        $this->assertSame(1, DB::table('audit_events')->where('event_uuid', sha1('logs:'.$legacyId))->count());

        $completed = DB::table('audit_events')->where('action', 'backfill.completed')->orderBy('id')->get();
        $second = json_decode($completed[1]->context, true);

        $this->assertCount(2, $completed);
        $this->assertSame(1, $second['scanned']);
        $this->assertSame(0, $second['inserted']);
        $this->assertSame(1, $second['skipped']);
    }

    public function test_limit_limita_las_filas_escaneadas_e_insertadas_por_fuente(): void
    {
        for ($index = 1; $index <= 5; $index++) {
            $this->logRow('pago_premio', ['pago_id' => $index], $this->user->id);
        }

        $this->artisan('audit:backfill', ['--source' => 'logs', '--limit' => 2, '--apply' => true])
            ->assertExitCode(0);

        $this->assertSame(2, DB::table('audit_events')->where('action', 'pago.prize_paid')->count());

        $context = json_decode(
            DB::table('audit_events')->where('action', 'backfill.completed')->sole()->context,
            true,
        );

        $this->assertSame(2, $context['scanned']);
        $this->assertSame(2, $context['inserted']);
        $this->assertSame(0, $context['skipped']);
    }

    public function test_juego_auditoria_apply_inserta_y_es_idempotente(): void
    {
        $this->seed(DatabaseSeeder::class);

        $juego = Juego::where('slug', 'triple-zulia')->firstOrFail();
        $createdAt = '2026-10-01 13:45:00';
        $rows = [
            ['activar', ['before' => ['active' => true], 'after' => ['active' => false]], 'game.toggled'],
            ['actualizar', ['before' => ['name' => 'A'], 'after' => ['name' => 'B']], 'game.updated'],
            ['premios', ['before' => ['base' => 500], 'after' => ['base' => 700]], 'game.prizes_updated'],
            ['desconocida', ['before' => null, 'after' => ['x' => 1]], 'game.updated'],
        ];

        $ids = [];

        foreach ($rows as $row) {
            $ids[] = (int) DB::table('juego_auditoria')->insertGetId([
                'juego_id' => $juego->id,
                'user_id' => $this->user->id,
                'accion' => $row[0],
                'cambios' => json_encode($row[1]),
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);
        }

        $expectedOccurredAt = CarbonImmutable::parse($createdAt, config('app.timezone'))
            ->utc()
            ->format('Y-m-d H:i:s');

        $this->artisan('audit:backfill', ['--source' => 'juego_auditoria', '--apply' => true])
            ->assertExitCode(0);

        $this->assertSame(
            4,
            DB::table('audit_events')
                ->where('action', '!=', 'backfill.completed')
                ->where('action', '!=', 'permission.catalog_synced')
                ->count(),
        );

        foreach ($rows as $index => $row) {
            $event = $this->eventByUuid(sha1('juego_auditoria:'.$ids[$index]));
            $context = json_decode($event->context, true);

            $this->assertSame($row[2], $event->action);
            $this->assertSame('juego', $event->entity_type);
            $this->assertSame($juego->id, (int) $event->entity_id);
            $this->assertSame('user', $event->actor_type);
            $this->assertSame($this->user->id, (int) $event->actor_id);
            $this->assertSame('l***@lotto.test', $event->actor_label);
            $this->assertSame($expectedOccurredAt, $event->occurred_at);
            $this->assertSame($row[1]['before'], $event->before === null ? null : json_decode($event->before, true));
            $this->assertSame($row[1]['after'], $event->after === null ? null : json_decode($event->after, true));
            $this->assertTrue($context['backfill']);
            $this->assertSame('juego_auditoria', $context['source']);
            $this->assertSame($ids[$index], $context['legacy_id']);
            $this->assertSame($row[0], $context['legacy_accion']);
        }

        $this->artisan('audit:backfill', ['--source' => 'juego_auditoria', '--apply' => true])
            ->assertExitCode(0);

        $this->assertSame(
            4,
            DB::table('audit_events')
                ->where('action', '!=', 'backfill.completed')
                ->where('action', '!=', 'permission.catalog_synced')
                ->count(),
        );

        $completed = DB::table('audit_events')->where('action', 'backfill.completed')->orderBy('id')->get();
        $second = json_decode($completed[1]->context, true);

        $this->assertCount(2, $completed);
        $this->assertSame(4, $second['scanned']);
        $this->assertSame(0, $second['inserted']);
        $this->assertSame(4, $second['skipped']);
    }

    public function test_hash_chain_exige_allow_chain_y_los_eventos_quedan_sin_hash(): void
    {
        config(['audit.hash_chain' => true]);

        $legacyId = $this->logRow('pago_premio', ['pago_id' => 9], $this->user->id);

        $this->artisan('audit:backfill', ['--source' => 'logs', '--apply' => true])->assertExitCode(1);

        $this->assertSame(0, DB::table('audit_events')->count());

        $this->artisan('audit:backfill', ['--source' => 'logs', '--apply' => true, '--allow-chain' => true])
            ->assertExitCode(0);

        $event = $this->eventByUuid(sha1('logs:'.$legacyId));

        $this->assertNull($event->hash);
        $this->assertNull($event->prev_hash);
        $this->assertSame(1, DB::table('audit_events')->where('action', 'backfill.completed')->count());
    }

    public function test_activation_code_y_mac_no_se_copian_crudos_en_before_after(): void
    {
        $legacyId = $this->logRow('activacion_taquilla', [
            'taquilla_id' => 99,
            'exitoso' => true,
            'before' => ['activation_code' => 'SECRET-CODE-1', 'mac_address' => 'AA:BB:CC:DD:EE:FF'],
            'after' => ['activation_code' => 'SECRET-CODE-1', 'mac_address' => 'AA:BB:CC:DD:EE:FF'],
        ]);

        $this->artisan('audit:backfill', ['--source' => 'logs', '--apply' => true])->assertExitCode(0);

        $event = $this->eventByUuid(sha1('logs:'.$legacyId));

        foreach (['before', 'after', 'context'] as $column) {
            $this->assertStringNotContainsString('SECRET-CODE-1', (string) $event->{$column});
            $this->assertStringNotContainsString('AA:BB:CC:DD:EE:FF', (string) $event->{$column});
        }
    }

    /**
     * @param  array<string, mixed>  $details
     */
    private function logRow(
        string $action,
        array $details = [],
        ?int $userId = null,
        string $createdAt = '2026-10-01 10:00:00',
    ): int {
        $timestamp = CarbonImmutable::parse($createdAt, config('app.timezone'))->format('Y-m-d H:i:s');

        return (int) DB::table('logs')->insertGetId([
            'user_id' => $userId,
            'action' => $action,
            'details' => json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'ip' => '190.0.0.1',
            'user_agent' => 'phpunit',
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
    }

    private function eventByUuid(string $eventUuid): ?object
    {
        return DB::table('audit_events')->where('event_uuid', $eventUuid)->first();
    }
}
