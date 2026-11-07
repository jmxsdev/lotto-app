<?php

namespace Tests\Feature;

use App\Exceptions\AuditAppendOnlyException;
use App\Models\Juego;
use App\Models\JuegoAuditoria;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * WU-12a — database-level integrity.
 *
 * The append-only triggers reject direct UPDATE/DELETE, the retention session
 * variable is the only DELETE bypass, the hardened FKs use `SET NULL`, and the
 * legacy `juego_auditoria` model is read-only.
 */
class AuditoriaIntegridadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('The append-only triggers require MySQL/MariaDB.');
        }

        $this->seed(DatabaseSeeder::class);
    }

    public function test_update_directo_en_audit_events_es_rechazado(): void
    {
        $id = $this->createAuditEvent();

        try {
            DB::table('audit_events')->where('id', $id)->update(['action' => 'game.toggled']);
            $this->fail('El trigger debe rechazar el UPDATE directo.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('append-only', $exception->getMessage());
        }

        $this->assertSame('game.updated', DB::table('audit_events')->where('id', $id)->value('action'));
    }

    public function test_delete_directo_en_audit_events_es_rechazado(): void
    {
        $id = $this->createAuditEvent();

        try {
            DB::table('audit_events')->where('id', $id)->delete();
            $this->fail('El trigger debe rechazar el DELETE directo.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('append-only', $exception->getMessage());
        }

        $this->assertDatabaseHas('audit_events', ['id' => $id]);
    }

    public function test_delete_es_permitido_solo_con_la_variable_de_retencion(): void
    {
        $id = $this->createAuditEvent();

        DB::statement('SET @audit_retention = 1');

        try {
            $deleted = DB::table('audit_events')->where('id', $id)->delete();
        } finally {
            DB::statement('SET @audit_retention = 0');
        }

        $this->assertSame(1, $deleted);
        $this->assertDatabaseMissing('audit_events', ['id' => $id]);
    }

    public function test_las_fks_de_evidencia_usan_set_null(): void
    {
        $rules = $this->foreignKeyRules();

        $this->assertSame('SET NULL', $rules['pagos.created_by'] ?? null);
        $this->assertSame('SET NULL', $rules['cierres_caja.created_by'] ?? null);
        $this->assertSame('SET NULL', $rules['juego_auditoria.user_id'] ?? null);
        $this->assertSame('SET NULL', $rules['juego_auditoria.juego_id'] ?? null);
    }

    public function test_juego_auditoria_es_read_only(): void
    {
        $user = User::where('email', 'super@lotto.com')->firstOrFail();
        $juego = Juego::where('slug', 'triple-zulia')->firstOrFail();

        $id = DB::table('juego_auditoria')->insertGetId([
            'juego_id' => $juego->id,
            'user_id' => $user->id,
            'accion' => 'actualizar',
            'cambios' => json_encode(['before' => ['name' => 'A'], 'after' => ['name' => 'B']]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $model = JuegoAuditoria::findOrFail($id);

        $this->assertReadOnly(fn () => JuegoAuditoria::create([
            'juego_id' => $juego->id,
            'user_id' => $user->id,
            'accion' => 'actualizar',
            'cambios' => ['before' => [], 'after' => []],
        ]));

        $this->assertReadOnly(fn () => $model->update(['accion' => 'premios']));
        $this->assertReadOnly(fn () => $model->delete());

        $this->assertDatabaseHas('juego_auditoria', ['id' => $id, 'accion' => 'actualizar']);
    }

    private function createAuditEvent(): int
    {
        return (int) DB::table('audit_events')->insertGetId([
            'event_uuid' => (string) Str::uuid(),
            'schema_version' => 1,
            'occurred_at' => now()->utc()->format('Y-m-d H:i:s'),
            'actor_type' => 'system',
            'action' => 'game.updated',
            'entity_type' => 'juego',
            'entity_id' => 1,
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function foreignKeyRules(): array
    {
        $rows = DB::select(<<<'SQL'
            SELECT kcu.TABLE_NAME AS table_name,
                   kcu.COLUMN_NAME AS column_name,
                   rc.DELETE_RULE AS delete_rule
            FROM information_schema.REFERENTIAL_CONSTRAINTS rc
            INNER JOIN information_schema.KEY_COLUMN_USAGE kcu
                ON kcu.CONSTRAINT_NAME = rc.CONSTRAINT_NAME
               AND kcu.CONSTRAINT_SCHEMA = rc.CONSTRAINT_SCHEMA
            WHERE rc.CONSTRAINT_SCHEMA = DATABASE()
              AND (
                  (kcu.TABLE_NAME = 'pagos' AND kcu.COLUMN_NAME = 'created_by')
                  OR (kcu.TABLE_NAME = 'cierres_caja' AND kcu.COLUMN_NAME = 'created_by')
                  OR (kcu.TABLE_NAME = 'juego_auditoria' AND kcu.COLUMN_NAME IN ('user_id', 'juego_id'))
              )
            SQL);

        $rules = [];

        foreach ($rows as $row) {
            $rules[$row->table_name.'.'.$row->column_name] = $row->delete_rule;
        }

        return $rules;
    }

    private function assertReadOnly(callable $callback): void
    {
        try {
            $callback();
            $this->fail('JuegoAuditoria debe rechazar la escritura.');
        } catch (AuditAppendOnlyException $exception) {
            $this->assertStringContainsString('read-only', $exception->getMessage());
        }
    }
}
