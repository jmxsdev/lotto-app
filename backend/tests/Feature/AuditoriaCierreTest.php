<?php

namespace Tests\Feature;

use App\Audit\AuditAction;
use App\Audit\AuditPriority;
use App\Models\Apuesta;
use App\Models\AuditEvent;
use App\Models\CierreCaja;
use App\Models\Grupo;
use App\Models\Juego;
use App\Models\Taquilla;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

/**
 * WU-5a — auditoría de cierre de caja.
 *
 * `cierre.created` es P1 best-effort (la creación nunca se bloquea);
 * `cierre.reclosed` es P0 fail-closed con before/after, motivo obligatorio y
 * rollback del cierre si la evidencia no se puede persistir.
 */
class AuditoriaCierreTest extends TestCase
{
    use RefreshDatabase;

    private static bool $failAudit = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Carbon::setTestNow(Carbon::create(2026, 8, 12, 12, 0, 0));

        AuditEvent::creating(static function (): void {
            if (self::$failAudit) {
                throw new RuntimeException('forced audit insert failure');
            }
        });
    }

    protected function tearDown(): void
    {
        self::$failAudit = false;
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function superUser(): User
    {
        return User::where('email', 'super@lotto.com')->firstOrFail();
    }

    private function bancaUser(): User
    {
        return User::where('email', 'banca@lotto.com')->firstOrFail();
    }

    private function taquillaSeeded(): Taquilla
    {
        return Taquilla::where('code', 'TT001')->firstOrFail();
    }

    private function grupoSeeded(): Grupo
    {
        return Grupo::where('code', 'GT001')->firstOrFail();
    }

    private function crearApuesta(Taquilla $taquilla, array $overrides = []): Apuesta
    {
        $juego = Juego::where('slug', 'lotto-activo')->firstOrFail();

        return Apuesta::create(array_merge([
            'taquilla_id' => $taquilla->id,
            'juego_id' => $juego->id,
            'combinacion' => json_encode(['animal' => 'Perro']),
            'amount_bs' => 0,
            'amount_usd' => 0,
            'exchange_rate_applied' => 36.50,
            'total_bs_equivalent' => 0,
            'estado' => 'pendiente',
            'fecha_hora' => now(),
            'sorteo_hora' => now()->addHour(),
        ], $overrides));
    }

    private function lastEvent(string $action): AuditEvent
    {
        return AuditEvent::query()->where('action', $action)->latest('id')->firstOrFail();
    }

    private function eventCount(string $action): int
    {
        return AuditEvent::query()->where('action', $action)->count();
    }

    private function assertTaquillaScope(AuditEvent $event, Taquilla $taquilla): void
    {
        $grupo = $this->grupoSeeded();

        $this->assertSame((int) $grupo->banca_id, $event->banca_id);
        $this->assertSame((int) $grupo->id, $event->grupo_id);
        $this->assertNull($event->agencia_id);
        $this->assertSame((int) $taquilla->id, $event->taquilla_id);
    }

    // ==================================================
    // Primer cierre — cierre.created P1 best-effort
    // ==================================================

    public function test_primer_cierre_emite_created_p1_con_actor_entidad_scope_y_after(): void
    {
        $actor = $this->superUser();
        $taquilla = $this->taquillaSeeded();
        $this->crearApuesta($taquilla, ['amount_bs' => 100, 'total_bs_equivalent' => 100, 'fecha_hora' => now()->subHour()]);

        $response = $this->actingAs($actor, 'sanctum')
            ->postJson('/api/v1/cierre', ['taquilla_id' => $taquilla->id]);

        $response->assertStatus(201)->assertJsonPath('reclosed', false);

        $this->assertSame(AuditPriority::P1, AuditAction::CierreCreated->priority());
        $this->assertSame(1, $this->eventCount('cierre.created'));
        $this->assertSame(0, $this->eventCount('cierre.reclosed'));

        $event = $this->lastEvent('cierre.created');

        $this->assertSame('user', $event->actor_type);
        $this->assertSame($actor->id, $event->actor_id);
        $this->assertSame('cierre_caja', $event->entity_type);
        $this->assertSame((int) $response->json('id'), (int) $event->entity_id);
        $this->assertSame('cierre.created', $event->action);

        $this->assertNull($event->before);
        $this->assertSame(100.0, (float) $event->after['total_ventas_bs']);
        $this->assertSame((int) $taquilla->id, (int) $event->after['taquilla_id']);
        $this->assertArrayNotHasKey('created_at', $event->after);
        $this->assertArrayNotHasKey('updated_at', $event->after);
        $this->assertArrayNotHasKey('clave_cierre', $event->after);

        $this->assertFalse($event->context['reclosed']);
        $this->assertTaquillaScope($event, $taquilla);
    }

    public function test_fallo_de_auditoria_en_primer_cierre_no_bloquea_el_cierre(): void
    {
        $actor = $this->superUser();
        $taquilla = $this->taquillaSeeded();
        $this->crearApuesta($taquilla, ['amount_bs' => 100, 'total_bs_equivalent' => 100, 'fecha_hora' => now()->subHour()]);

        self::$failAudit = true;

        $response = $this->actingAs($actor, 'sanctum')
            ->postJson('/api/v1/cierre', ['taquilla_id' => $taquilla->id]);

        $response->assertStatus(201)->assertJsonPath('reclosed', false);

        $this->assertDatabaseHas('cierres_caja', ['taquilla_id' => $taquilla->id]);
        $this->assertSame(1, CierreCaja::where('taquilla_id', $taquilla->id)->count());
        $this->assertSame(0, $this->eventCount('cierre.created'));
    }

    // ==================================================
    // Re-cierre — cierre.reclosed P0 fail-closed
    // ==================================================

    public function test_re_cierre_con_motivo_emite_reclosed_p0_con_before_after_scope_y_motivo(): void
    {
        $actor = $this->superUser();
        $taquilla = $this->taquillaSeeded();
        $this->bancaUser()->update(['clave_cierre' => Hash::make('123456')]);
        $this->crearApuesta($taquilla, ['amount_bs' => 100, 'total_bs_equivalent' => 100, 'fecha_hora' => now()->subHour()]);

        $primero = $this->actingAs($actor, 'sanctum')
            ->postJson('/api/v1/cierre', ['taquilla_id' => $taquilla->id]);
        $primero->assertStatus(201);
        $id = (int) $primero->json('id');

        Carbon::setTestNow(Carbon::create(2026, 8, 12, 13, 0, 0));
        $this->crearApuesta($taquilla, [
            'amount_bs' => 300,
            'total_bs_equivalent' => 300,
            'fecha_hora' => Carbon::create(2026, 8, 12, 12, 30, 0),
        ]);

        $response = $this->actingAs($actor, 'sanctum')->postJson('/api/v1/cierre', [
            'taquilla_id' => $taquilla->id,
            'clave_cierre' => '123456',
            'reason_code' => 'data_correction',
            'reason_text' => 'Ajuste de cierre del día',
        ]);

        $response->assertStatus(200)->assertJsonPath('reclosed', true)->assertJsonPath('id', $id);

        $this->assertSame(AuditPriority::P0, AuditAction::CierreReclosed->priority());
        $this->assertSame(1, $this->eventCount('cierre.reclosed'));

        $event = $this->lastEvent('cierre.reclosed');

        $this->assertSame('cierre_caja', $event->entity_type);
        $this->assertSame($id, (int) $event->entity_id);
        $this->assertSame('data_correction', $event->reason_code);
        $this->assertSame('Ajuste de cierre del día', $event->reason_text);

        $this->assertSame(100.0, (float) $event->before['total_ventas_bs']);
        $this->assertSame(400.0, (float) $event->after['total_ventas_bs']);
        $this->assertNull($event->before['reclosed_by']);
        $this->assertNull($event->before['reclosed_at']);
        $this->assertSame((int) $actor->id, (int) $event->after['reclosed_by']);
        $this->assertNotNull($event->after['reclosed_at']);

        $this->assertTrue($event->context['reclosed']);
        $this->assertSame((int) $actor->id, (int) $event->context['reclosed_by']);
        $this->assertTaquillaScope($event, $taquilla);
    }

    public function test_re_cierre_sin_motivo_responde_422_y_no_modifica_la_fila(): void
    {
        $actor = $this->superUser();
        $taquilla = $this->taquillaSeeded();
        $this->bancaUser()->update(['clave_cierre' => Hash::make('123456')]);
        $this->crearApuesta($taquilla, ['amount_bs' => 100, 'total_bs_equivalent' => 100, 'fecha_hora' => now()->subHour()]);

        $primero = $this->actingAs($actor, 'sanctum')
            ->postJson('/api/v1/cierre', ['taquilla_id' => $taquilla->id]);
        $primero->assertStatus(201);
        $id = (int) $primero->json('id');

        $original = CierreCaja::findOrFail($id);
        $fechaFinOriginal = $original->fecha_fin->format('Y-m-d H:i:s');
        $totalOriginal = (string) $original->total_ventas_bs;

        Carbon::setTestNow(Carbon::create(2026, 8, 12, 13, 0, 0));

        // Sin motivo
        $this->actingAs($actor, 'sanctum')
            ->postJson('/api/v1/cierre', ['taquilla_id' => $taquilla->id, 'clave_cierre' => '123456'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'El motivo es requerido para esta acción.');

        // Código de motivo inválido
        $this->actingAs($actor, 'sanctum')
            ->postJson('/api/v1/cierre', [
                'taquilla_id' => $taquilla->id,
                'clave_cierre' => '123456',
                'reason_code' => 'not_a_reason',
            ])
            ->assertStatus(422);

        $fila = CierreCaja::findOrFail($id);
        $this->assertSame($fechaFinOriginal, $fila->fecha_fin->format('Y-m-d H:i:s'));
        $this->assertSame($totalOriginal, (string) $fila->total_ventas_bs);
        $this->assertNull($fila->reclosed_by);
        $this->assertNull($fila->reclosed_at);
        $this->assertSame(0, $this->eventCount('cierre.reclosed'));
    }

    public function test_re_cierre_con_clave_invalida_responde_422_sin_evento_reclosed(): void
    {
        $actor = $this->superUser();
        $taquilla = $this->taquillaSeeded();
        $this->bancaUser()->update(['clave_cierre' => Hash::make('123456')]);
        $this->crearApuesta($taquilla, ['amount_bs' => 100, 'total_bs_equivalent' => 100, 'fecha_hora' => now()->subHour()]);

        $primero = $this->actingAs($actor, 'sanctum')
            ->postJson('/api/v1/cierre', ['taquilla_id' => $taquilla->id]);
        $primero->assertStatus(201);
        $id = (int) $primero->json('id');

        Carbon::setTestNow(Carbon::create(2026, 8, 12, 13, 0, 0));

        $this->actingAs($actor, 'sanctum')
            ->postJson('/api/v1/cierre', [
                'taquilla_id' => $taquilla->id,
                'clave_cierre' => '999999',
                'reason_code' => 'data_correction',
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Clave de cierre incorrecta.');

        $fila = CierreCaja::findOrFail($id);
        $this->assertNull($fila->reclosed_by);
        $this->assertNull($fila->reclosed_at);
        $this->assertSame(0, $this->eventCount('cierre.reclosed'));
    }

    public function test_fallo_de_auditoria_en_re_cierre_responde_500_y_revierte_la_fila(): void
    {
        $actor = $this->superUser();
        $taquilla = $this->taquillaSeeded();
        $this->bancaUser()->update(['clave_cierre' => Hash::make('123456')]);
        $this->crearApuesta($taquilla, ['amount_bs' => 100, 'total_bs_equivalent' => 100, 'fecha_hora' => now()->subHour()]);

        $primero = $this->actingAs($actor, 'sanctum')
            ->postJson('/api/v1/cierre', ['taquilla_id' => $taquilla->id]);
        $primero->assertStatus(201);
        $id = (int) $primero->json('id');

        $original = CierreCaja::findOrFail($id);
        $fechaFinOriginal = $original->fecha_fin->format('Y-m-d H:i:s');
        $totalOriginal = (string) $original->total_ventas_bs;

        Carbon::setTestNow(Carbon::create(2026, 8, 12, 13, 0, 0));
        $this->crearApuesta($taquilla, [
            'amount_bs' => 300,
            'total_bs_equivalent' => 300,
            'fecha_hora' => Carbon::create(2026, 8, 12, 12, 30, 0),
        ]);

        self::$failAudit = true;

        $this->actingAs($actor, 'sanctum')
            ->postJson('/api/v1/cierre', [
                'taquilla_id' => $taquilla->id,
                'clave_cierre' => '123456',
                'reason_code' => 'data_correction',
            ])
            ->assertStatus(500);

        $fila = CierreCaja::findOrFail($id);
        $this->assertSame($fechaFinOriginal, $fila->fecha_fin->format('Y-m-d H:i:s'));
        $this->assertSame($totalOriginal, (string) $fila->total_ventas_bs);
        $this->assertNull($fila->reclosed_by);
        $this->assertNull($fila->reclosed_at);
        $this->assertSame(0, $this->eventCount('cierre.reclosed'));
    }

    public function test_evento_de_re_cierre_nunca_contiene_la_clave_de_cierre(): void
    {
        $actor = $this->superUser();
        $taquilla = $this->taquillaSeeded();
        $this->bancaUser()->update(['clave_cierre' => Hash::make('123456')]);
        $this->crearApuesta($taquilla, ['amount_bs' => 100, 'total_bs_equivalent' => 100, 'fecha_hora' => now()->subHour()]);

        $primero = $this->actingAs($actor, 'sanctum')
            ->postJson('/api/v1/cierre', ['taquilla_id' => $taquilla->id]);
        $primero->assertStatus(201);

        Carbon::setTestNow(Carbon::create(2026, 8, 12, 13, 0, 0));

        $this->actingAs($actor, 'sanctum')
            ->postJson('/api/v1/cierre', [
                'taquilla_id' => $taquilla->id,
                'clave_cierre' => '123456',
                'reason_code' => 'data_correction',
            ])
            ->assertStatus(200);

        $event = $this->lastEvent('cierre.reclosed');

        foreach (['before', 'after', 'context'] as $column) {
            $this->assertArrayNotHasKey('clave_cierre', $event->{$column} ?? []);
        }

        $payload = json_encode($event->getAttributes(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $this->assertStringNotContainsString('clave_cierre', $payload);
        $this->assertStringNotContainsString('123456', $payload);
    }
}
