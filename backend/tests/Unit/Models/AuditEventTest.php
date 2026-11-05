<?php

namespace Tests\Unit\Models;

use App\Exceptions\AuditAppendOnlyException;
use App\Models\AuditEvent;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class AuditEventTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'event_uuid' => (string) Str::uuid(),
            'schema_version' => 1,
            'occurred_at' => new DateTimeImmutable('2026-11-05 15:04:05', new DateTimeZone('UTC')),
            'actor_type' => 'user',
            'actor_id' => 10,
            'actor_label' => 'm***@test.com',
            'actor_roles' => ['master'],
            'action' => 'banca.updated',
            'entity_type' => 'banca',
            'entity_id' => 5,
            'banca_id' => 5,
            'grupo_id' => null,
            'agencia_id' => null,
            'taquilla_id' => null,
            'before' => ['name' => 'Anterior'],
            'after' => ['name' => 'Nueva'],
            'context' => ['route' => 'api/v1/bancas/5'],
            'reason_code' => 'data_correction',
            'reason_text' => 'Corrección',
            'ip' => '127.0.0.1',
            'user_agent' => 'phpunit',
            'request_id' => 'req-1',
        ], $overrides);
    }

    public function test_crear_evento_persiste_y_castea_los_campos(): void
    {
        $event = AuditEvent::create($this->payload());

        $this->assertDatabaseHas('audit_events', [
            'event_uuid' => $event->event_uuid,
            'action' => 'banca.updated',
            'entity_type' => 'banca',
        ]);

        $this->assertInstanceOf(DateTimeImmutable::class, $event->occurred_at);
        $this->assertSame('UTC', $event->occurred_at->getTimezone()->getName());
        $this->assertSame('2026-11-05 15:04:05', $event->occurred_at->format('Y-m-d H:i:s'));
        $this->assertSame(['master'], $event->actor_roles);
        $this->assertSame(['name' => 'Anterior'], $event->before);
        $this->assertSame(['name' => 'Nueva'], $event->after);
        $this->assertSame(['route' => 'api/v1/bancas/5'], $event->context);
    }

    public function test_el_modelo_no_usa_timestamps_ni_columnas_created_at_updated_at(): void
    {
        $event = new AuditEvent;

        $this->assertFalse($event->usesTimestamps());
        $this->assertFalse(Schema::hasColumn('audit_events', 'created_at'));
        $this->assertFalse(Schema::hasColumn('audit_events', 'updated_at'));
    }

    public function test_update_lanza_audit_append_only_exception(): void
    {
        $event = AuditEvent::create($this->payload());
        $event->reason_text = 'Intento de cambio';

        $this->expectException(AuditAppendOnlyException::class);

        $event->save();
    }

    public function test_update_no_modifica_la_fila_persistida(): void
    {
        $event = AuditEvent::create($this->payload());
        $event->reason_text = 'Intento de cambio';

        try {
            $event->save();
            $this->fail('El update debió lanzar AuditAppendOnlyException.');
        } catch (AuditAppendOnlyException) {
            // Expected: append-only model.
        }

        $this->assertSame(
            'Corrección',
            DB::table('audit_events')->where('id', $event->id)->value('reason_text'),
        );
    }

    public function test_delete_lanza_audit_append_only_exception(): void
    {
        $event = AuditEvent::create($this->payload());

        $this->expectException(AuditAppendOnlyException::class);

        $event->delete();
    }

    public function test_delete_no_elimina_la_fila_persistida(): void
    {
        $event = AuditEvent::create($this->payload());

        try {
            $event->delete();
            $this->fail('El delete debió lanzar AuditAppendOnlyException.');
        } catch (AuditAppendOnlyException) {
            // Expected: append-only model.
        }

        $this->assertDatabaseHas('audit_events', ['id' => $event->id]);
    }

    public function test_serialize_date_devuelve_iso_8601_utc(): void
    {
        $event = AuditEvent::create($this->payload());

        $this->assertSame('2026-11-05T15:04:05Z', $event->toArray()['occurred_at']);
    }

    public function test_audit_chain_state_arranca_con_una_sola_fila(): void
    {
        $this->assertSame(1, DB::table('audit_chain_state')->count());

        $state = DB::table('audit_chain_state')->where('id', 1)->first();

        $this->assertNotNull($state);
        $this->assertNull($state->last_event_id);
        $this->assertNull($state->last_hash);
    }
}
