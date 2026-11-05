<?php

namespace Tests\Unit\Audit;

use App\Audit\AuditAction;
use App\Audit\AuditActorType;
use App\Audit\AuditEventData;
use App\Audit\AuditReason;
use App\Audit\AuditScope;
use App\Models\Banca;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Tests\TestCase;

class AuditEventDataTest extends TestCase
{
    private function banca(int $id = 7): Banca
    {
        $banca = new Banca;
        $banca->id = $id;

        return $banca;
    }

    public function test_for_model_en_create_fuerza_before_null(): void
    {
        $data = AuditEventData::forModel(
            $this->banca(),
            AuditAction::BancaCreated,
            ['name' => 'Anterior'],
            ['name' => 'Nueva'],
        );

        $this->assertNull($data->before);
        $this->assertSame(['name' => 'Nueva'], $data->after);
        $this->assertSame('banca', $data->entityType);
        $this->assertSame(7, $data->entityId);
        $this->assertSame(AuditActorType::User, $data->actorType);
    }

    public function test_for_model_en_update_conserva_el_diff(): void
    {
        $data = AuditEventData::forModel(
            $this->banca(15),
            AuditAction::BancaUpdated,
            ['name' => 'Anterior'],
            ['name' => 'Nueva'],
            ['route' => 'api/v1/bancas/15'],
        );

        $this->assertSame(['name' => 'Anterior'], $data->before);
        $this->assertSame(['name' => 'Nueva'], $data->after);
        $this->assertSame(15, $data->entityId);
        $this->assertSame(['route' => 'api/v1/bancas/15'], $data->context);
    }

    public function test_for_model_en_delete_conserva_snapshot_y_fuerza_after_null(): void
    {
        $data = AuditEventData::forModel(
            $this->banca(21),
            AuditAction::BancaDeleted,
            ['name' => 'Snapshot'],
            ['name' => 'No debe persistir'],
        );

        $this->assertSame(['name' => 'Snapshot'], $data->before);
        $this->assertNull($data->after);
    }

    public function test_for_model_en_limit_deleted_tambien_fuerza_after_null(): void
    {
        $data = AuditEventData::forModel(
            $this->banca(30),
            AuditAction::GameLimitDeleted,
            ['limite' => 100],
            ['limite' => 200],
        );

        $this->assertSame(['limite' => 100], $data->before);
        $this->assertNull($data->after);
        $this->assertSame('juego_limite', $data->entityType);
    }

    public function test_for_model_sin_clave_deja_entity_id_null(): void
    {
        $data = AuditEventData::forModel(new Banca, AuditAction::BancaUpdated);

        $this->assertNull($data->entityId);
    }

    public function test_with_reason_es_inmutable_y_normaliza_el_texto(): void
    {
        $original = new AuditEventData(
            action: AuditAction::UserDeleted,
            reasonText: '   ',
        );

        $conMotivo = $original->withReason(AuditReason::EmployeeOffboarding, '  Baja voluntaria  ');

        $this->assertNull($original->reasonCode);
        $this->assertNull($original->reasonText);
        $this->assertSame('employee_offboarding', $conMotivo->reasonCode);
        $this->assertSame('Baja voluntaria', $conMotivo->reasonText);
        $this->assertSame($original->eventUuid, $conMotivo->eventUuid);
        $this->assertSame(
            $original->occurredAt->format('Y-m-d H:i:s'),
            $conMotivo->occurredAt->format('Y-m-d H:i:s'),
        );
    }

    public function test_reason_text_se_trunca_a_quinientos_caracteres(): void
    {
        $texto = str_repeat('x', 650);

        $desdeConstructor = new AuditEventData(
            action: AuditAction::UserDeleted,
            reasonText: $texto,
        );

        $desdeWithReason = (new AuditEventData(action: AuditAction::UserDeleted))
            ->withReason(AuditReason::Other, $texto);

        $this->assertSame(500, mb_strlen($desdeConstructor->reasonText));
        $this->assertSame(500, mb_strlen($desdeWithReason->reasonText));
    }

    public function test_un_reason_code_fuera_del_enum_es_rechazado(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new AuditEventData(
            action: AuditAction::UserDeleted,
            reasonCode: 'texto libre',
        );
    }

    public function test_event_uuid_se_genera_si_no_se_entrega(): void
    {
        $data = new AuditEventData(action: AuditAction::AuthLogout);

        $this->assertSame(36, strlen($data->eventUuid));
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $data->eventUuid,
        );
    }

    public function test_occurred_at_se_normaliza_a_utc(): void
    {
        $caracas = new DateTimeImmutable('2026-11-05 10:30:00', new DateTimeZone('America/Caracas'));

        $data = new AuditEventData(
            action: AuditAction::BancaUpdated,
            occurredAt: $caracas,
        );

        $this->assertSame('UTC', $data->occurredAt->getTimezone()->getName());
        $this->assertSame('2026-11-05 14:30:00', $data->occurredAt->format('Y-m-d H:i:s'));
    }

    public function test_to_row_expone_el_envelope_completo(): void
    {
        $data = new AuditEventData(
            action: AuditAction::BancaUpdated,
            eventUuid: '00000000-0000-4000-8000-000000000001',
            occurredAt: new DateTimeImmutable('2026-11-05 15:04:05', new DateTimeZone('UTC')),
            actorType: AuditActorType::User,
            actorId: 3,
            actorLabel: 'm***@test.com',
            actorRoles: ['master'],
            entityType: 'banca',
            entityId: 9,
            scope: new AuditScope(bancaId: 9, grupoId: 2),
            before: ['name' => 'Anterior'],
            after: ['name' => 'Nueva'],
            context: ['route' => 'api/v1/bancas/9'],
            reasonCode: 'data_correction',
            reasonText: 'Corrección',
            ip: '127.0.0.1',
            userAgent: 'phpunit',
            requestId: 'req-1',
        );

        $row = $data->toRow();

        $this->assertSame([
            'event_uuid',
            'schema_version',
            'occurred_at',
            'actor_type',
            'actor_id',
            'actor_label',
            'actor_roles',
            'action',
            'entity_type',
            'entity_id',
            'banca_id',
            'grupo_id',
            'agencia_id',
            'taquilla_id',
            'before',
            'after',
            'context',
            'reason_code',
            'reason_text',
            'ip',
            'user_agent',
            'request_id',
            'job_id',
            'hash',
            'prev_hash',
        ], array_keys($row));

        $this->assertSame('00000000-0000-4000-8000-000000000001', $row['event_uuid']);
        $this->assertSame(1, $row['schema_version']);
        $this->assertSame('2026-11-05 15:04:05', $row['occurred_at']);
        $this->assertSame('user', $row['actor_type']);
        $this->assertSame('banca.updated', $row['action']);
        $this->assertSame(9, $row['banca_id']);
        $this->assertSame(2, $row['grupo_id']);
        $this->assertNull($row['agencia_id']);
        $this->assertNull($row['taquilla_id']);
        $this->assertSame(['name' => 'Anterior'], $row['before']);
        $this->assertSame(['name' => 'Nueva'], $row['after']);
        $this->assertSame(['route' => 'api/v1/bancas/9'], $row['context']);
        $this->assertSame('data_correction', $row['reason_code']);
        $this->assertNull($row['job_id']);
        $this->assertNull($row['hash']);
        $this->assertNull($row['prev_hash']);
    }

    public function test_to_row_deja_context_null_cuando_esta_vacio(): void
    {
        $row = (new AuditEventData(action: AuditAction::AuthLogout))->toRow();

        $this->assertNull($row['context']);
        $this->assertNull($row['banca_id']);
        $this->assertNull($row['reason_code']);
        $this->assertNull($row['reason_text']);
    }
}
