<?php

namespace Tests\Feature;

use App\Audit\AuditAction;
use App\Audit\AuditContext;
use App\Audit\AuditReason;
use App\Audit\AuditRedactor;
use App\Audit\Auditable;
use App\Models\AuditEvent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AuditableTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(AuditContext::class)->setActor(null, 'admin@example.com', ['super_master']);
    }

    public function test_crear_actualizar_y_borrar_generan_eventos_con_actor_scope_diff_y_redaccion(): void
    {
        $bancaId = (int) DB::table('bancas')->insertGetId([
            'name' => 'Banca Auditoría',
            'code' => 'AUD-1',
        ]);

        $probe = DB::transaction(fn (): AuditableProbe => AuditableProbe::create([
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'password' => 'plain-secret',
            'banca_id' => $bancaId,
        ]));

        DB::transaction(function () use ($probe): void {
            $probe->update([
                'name' => 'Janet Doe',
                'email' => 'janet@example.com',
            ]);
        });

        app(AuditContext::class)->withReason(AuditReason::EmployeeOffboarding, 'Offboarding');

        DB::transaction(function () use ($probe): void {
            $probe->delete();
        });

        $events = AuditEvent::query()->orderBy('id')->get();

        $this->assertCount(3, $events);

        $created = $events[0];
        $this->assertSame('user.created', $created->action);
        $this->assertSame('user', $created->entity_type);
        $this->assertSame((int) $probe->getKey(), $created->entity_id);
        $this->assertSame('user', $created->actor_type);
        $this->assertSame('admin@example.com', $created->actor_label);
        $this->assertSame(['super_master'], $created->actor_roles);
        $this->assertSame($bancaId, $created->banca_id);
        $this->assertNull($created->before);
        $this->assertSame('Jane Doe', $created->after['name']);
        $this->assertSame(AuditRedactor::REDACTED, $created->after['email']);
        $this->assertArrayNotHasKey('password', $created->after);
        $this->assertSame('created', $created->context['model_event']);
        $this->assertSame(AuditableProbe::class, $created->context['model']);

        $updated = $events[1];
        $this->assertSame('user.updated', $updated->action);
        $this->assertSame('Jane Doe', $updated->before['name']);
        $this->assertSame('Janet Doe', $updated->after['name']);
        $this->assertSame(AuditRedactor::REDACTED, $updated->before['email']);
        $this->assertSame(AuditRedactor::REDACTED, $updated->after['email']);
        $this->assertArrayNotHasKey('password', $updated->before);
        $this->assertArrayNotHasKey('password', $updated->after);

        $deleted = $events[2];
        $this->assertSame('user.deleted', $deleted->action);
        $this->assertSame('Janet Doe', $deleted->before['name']);
        $this->assertNull($deleted->after);
        $this->assertArrayNotHasKey('password', $deleted->before);
        $this->assertSame('employee_offboarding', $deleted->reason_code);
        $this->assertSame('Offboarding', $deleted->reason_text);
        $this->assertSame($bancaId, $deleted->banca_id);
    }

    public function test_un_modelo_sin_action_map_no_audita(): void
    {
        $probe = AuditableProbeWithoutMap::create([
            'name' => 'Silent',
            'email' => 'silent@example.com',
            'password' => 'plain-secret',
        ]);

        $probe->update(['name' => 'Still silent']);
        $probe->delete();

        $this->assertDatabaseCount('audit_events', 0);
    }
}

class AuditableProbe extends Model
{
    use Auditable;

    protected $table = 'users';

    public $timestamps = false;

    protected $guarded = [];

    protected function auditActionMap(): array
    {
        return [
            'created' => AuditAction::UserCreated,
            'updated' => AuditAction::UserUpdated,
            'deleted' => AuditAction::UserDeleted,
        ];
    }

    protected function auditExclude(): array
    {
        return ['password', 'remember_token', 'clave_cierre'];
    }

    protected function auditRedact(): array
    {
        return ['email'];
    }
}

class AuditableProbeWithoutMap extends Model
{
    use Auditable;

    protected $table = 'users';

    public $timestamps = false;

    protected $guarded = [];
}
