<?php

namespace Tests\Unit\Audit;

use App\Audit\AuditAction;
use App\Audit\AuditChainHasher;
use App\Audit\AuditContext;
use App\Audit\AuditEventData;
use App\Audit\AuditReason;
use App\Audit\AuditRecorder;
use App\Audit\AuditRedactor;
use App\Audit\AuditScope;
use App\Audit\AuditScopeResolver;
use App\Exceptions\AuditValidationException;
use App\Exceptions\AuditWriteException;
use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class AuditRecorderTest extends TestCase
{
    use RefreshDatabase;

    private static bool $failInsert = false;

    private AuditContext $context;

    private AuditRecorder $recorder;

    private AuditChainHasher $hasher;

    protected function setUp(): void
    {
        parent::setUp();

        AuditEvent::creating(static function (): void {
            if (self::$failInsert) {
                throw new RuntimeException('forced audit insert failure');
            }
        });

        self::$failInsert = false;
        $this->context = new AuditContext;
        $this->hasher = new AuditChainHasher;
        $this->recorder = new AuditRecorder(
            $this->context,
            new AuditRedactor,
            new AuditScopeResolver,
            $this->hasher,
        );
    }

    protected function tearDown(): void
    {
        self::$failInsert = false;
        Auth::forgetGuards();

        parent::tearDown();
    }

    private function request(array $headers = []): Request
    {
        $server = ['REMOTE_ADDR' => '190.0.0.1'];

        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        $request = Request::create('/api/v1/bancas/5', 'PUT', [], [], [], $server);
        $request->setRouteResolver(static fn () => new Route(['PUT'], 'api/v1/bancas/{banca}', []));

        return $request;
    }

    private function actor(int $id = 7, string $email = 'jdoe@example.com', array $roles = ['master']): User
    {
        $user = new User(['name' => 'J Doe', 'email' => $email]);
        $user->id = $id;
        $user->setRelation('roles', collect(array_map(
            static fn (string $role): object => (object) ['name' => $role],
            $roles,
        )));

        return $user;
    }

    private function storedPayload(int $id): array
    {
        $row = (array) DB::table('audit_events')->where('id', $id)->first();

        foreach (['actor_roles', 'before', 'after', 'context'] as $key) {
            if (isset($row[$key]) && is_string($row[$key])) {
                $row[$key] = json_decode($row[$key], true);
            }
        }

        foreach (['schema_version', 'actor_id', 'entity_id', 'banca_id', 'grupo_id', 'agencia_id', 'taquilla_id'] as $key) {
            if (($row[$key] ?? null) !== null) {
                $row[$key] = (int) $row[$key];
            }
        }

        unset($row['id'], $row['hash'], $row['prev_hash']);

        return $row;
    }

    public function test_record_persiste_evento_p1_con_actor_contexto_scope_y_redaccion(): void
    {
        $this->context->captureRequest($this->request([
            'X-Request-Id' => 'req-12345678',
            'X-Panel' => '1',
            'User-Agent' => 'phpunit-agent',
        ]));
        Auth::setUser($this->actor());

        $event = $this->recorder->record(AuditAction::BancaUpdated, new AuditEventData(
            action: AuditAction::BancaUpdated,
            entityType: 'banca',
            entityId: 5,
            scope: new AuditScope(bancaId: 3),
            before: ['name' => 'Anterior', 'password' => 'secret'],
            after: ['name' => 'Nueva', 'token' => 'abc123'],
            context: ['filters' => ['name' => 'Nueva'], 'clave_cierre' => '9876'],
            reasonCode: AuditReason::DataCorrection->value,
            reasonText: 'Corrección token=abc123',
            jobId: 'job-42',
        ));

        $this->assertNotNull($event);
        $this->assertDatabaseHas('audit_events', [
            'id' => $event->id,
            'action' => 'banca.updated',
            'actor_type' => 'user',
            'actor_id' => 7,
            'actor_label' => 'j***@example.com',
            'entity_type' => 'banca',
            'entity_id' => 5,
            'banca_id' => 3,
            'request_id' => 'req-12345678',
            'job_id' => 'job-42',
            'ip' => '190.0.0.1',
            'user_agent' => 'phpunit-agent',
            'reason_code' => 'data_correction',
        ]);

        $stored = AuditEvent::find($event->id);

        $this->assertSame(['master'], $stored->actor_roles);
        $this->assertSame(AuditRedactor::REDACTED, $stored->before['password']);
        $this->assertSame('Anterior', $stored->before['name']);
        $this->assertSame(AuditRedactor::REDACTED, $stored->after['token']);
        $this->assertSame('Nueva', $stored->after['name']);
        $this->assertSame(['name' => 'Nueva'], $stored->context['filters']);
        $this->assertSame(AuditRedactor::REDACTED, $stored->context['clave_cierre']);
        $this->assertSame('PUT /api/v1/bancas/{banca}', $stored->context['route']);
        $this->assertArrayNotHasKey('request_id', $stored->context);
        $this->assertArrayNotHasKey('job_id', $stored->context);
        $this->assertStringNotContainsString('abc123', (string) $stored->reason_text);
        $this->assertStringContainsString(AuditRedactor::REDACTED, (string) $stored->reason_text);
    }

    public function test_actor_label_explicito_se_enmascara_cuando_es_email(): void
    {
        $event = $this->recorder->record(AuditAction::BancaUpdated, new AuditEventData(
            action: AuditAction::BancaUpdated,
            actorId: 7,
            actorLabel: 'jdoe@example.com',
            entityType: 'banca',
            entityId: 5,
        ));

        $this->assertNotNull($event);
        $this->assertSame('j***@example.com', $event->actor_label);
        $this->assertStringNotContainsString(
            'jdoe@example.com',
            (string) json_encode($event->getAttributes()),
        );
    }

    public function test_enabled_false_no_escribe(): void
    {
        config(['audit.enabled' => false]);

        $event = $this->recorder->record(AuditAction::BancaUpdated, new AuditEventData(action: AuditAction::BancaUpdated));

        $this->assertNull($event);
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_motivo_requerido_faltante_lanza_validacion_sin_fila(): void
    {
        $calls = [
            fn () => DB::transaction(fn () => $this->recorder->critical(
                AuditAction::UserDeleted,
                new AuditEventData(action: AuditAction::UserDeleted),
            )),
            fn () => $this->recorder->recordMany(AuditAction::UserDeleted, [
                new AuditEventData(action: AuditAction::UserDeleted),
            ]),
        ];

        foreach ($calls as $call) {
            try {
                $call();
                $this->fail('El evento sin motivo debió rechazarse.');
            } catch (AuditValidationException $exception) {
                $this->assertSame('reason_code', array_key_first($exception->errors()));
            }
        }

        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_critical_en_transaccion_persiste_y_rellena_motivo_automatico(): void
    {
        $this->context->setActor(null, 'j***@example.com', []);

        $event = DB::transaction(fn () => $this->recorder->critical(
            AuditAction::AuthLoginFailed,
            new AuditEventData(action: AuditAction::AuthLoginFailed),
        ));

        $this->assertSame('security_incident', $event->reason_code);
        $this->assertDatabaseHas('audit_events', [
            'id' => $event->id,
            'action' => 'auth.login_failed',
            'actor_label' => 'j***@example.com',
            'reason_code' => 'security_incident',
        ]);
    }

    public function test_critical_sin_transaccion_lanza_write_exception(): void
    {
        DB::commit();

        try {
            $this->recorder->critical(AuditAction::AuthLoginSucceeded, new AuditEventData(action: AuditAction::AuthLoginSucceeded));
            $this->fail('La escritura crítica debió exigir una transacción.');
        } catch (AuditWriteException) {
            // Expected: fail-closed without a business transaction.
        } finally {
            if (DB::transactionLevel() === 0) {
                DB::beginTransaction();
            }
        }

        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_critical_con_fallo_forzado_hace_rollback_y_no_deja_fila(): void
    {
        self::$failInsert = true;

        try {
            DB::transaction(fn () => $this->recorder->critical(
                AuditAction::AuthLoginSucceeded,
                new AuditEventData(action: AuditAction::AuthLoginSucceeded),
            ));
            $this->fail('La inserción forzada debió fallar.');
        } catch (AuditWriteException) {
            // Expected: critical never hides a write failure.
        } finally {
            self::$failInsert = false;
        }

        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_best_effort_con_fallo_forzado_registra_error_y_no_lanza(): void
    {
        Log::spy();
        self::$failInsert = true;

        $event = $this->recorder->bestEffort(AuditAction::BancaUpdated, new AuditEventData(action: AuditAction::BancaUpdated));

        self::$failInsert = false;

        $this->assertNull($event);
        $this->assertDatabaseCount('audit_events', 0);
        Log::shouldHaveReceived('error')->once();
    }

    public function test_idempotencia_por_event_uuid(): void
    {
        $data = new AuditEventData(action: AuditAction::BancaUpdated, eventUuid: (string) Str::uuid());

        $first = $this->recorder->bestEffort(AuditAction::BancaUpdated, $data);
        $second = $this->recorder->bestEffort(AuditAction::BancaUpdated, $data);

        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertSame($first->id, $second->id);
        $this->assertDatabaseCount('audit_events', 1);
    }

    public function test_record_many_inserta_varios_e_ignora_duplicados(): void
    {
        $uuid = (string) Str::uuid();

        $count = $this->recorder->recordMany(AuditAction::BancaUpdated, [
            new AuditEventData(action: AuditAction::BancaUpdated, eventUuid: $uuid),
            new AuditEventData(action: AuditAction::BancaUpdated),
            new AuditEventData(action: AuditAction::BancaUpdated, eventUuid: $uuid),
        ]);

        $this->assertSame(3, $count);
        $this->assertDatabaseCount('audit_events', 2);
    }

    public function test_hash_chain_encadena_eventos_y_actualiza_estado(): void
    {
        config(['audit.hash_chain' => true]);

        $first = DB::transaction(fn () => $this->recorder->critical(
            AuditAction::AuthLoginSucceeded,
            new AuditEventData(action: AuditAction::AuthLoginSucceeded, eventUuid: 'event-1'),
        ));
        $second = DB::transaction(fn () => $this->recorder->critical(
            AuditAction::AuthLogout,
            new AuditEventData(action: AuditAction::AuthLogout, eventUuid: 'event-2'),
        ));

        $this->assertNull($first->prev_hash);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $first->hash);
        $this->assertSame($first->hash, $second->prev_hash);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $second->hash);

        $state = DB::table('audit_chain_state')->where('id', 1)->first();

        $this->assertSame($second->id, (int) $state->last_event_id);
        $this->assertSame($second->hash, $state->last_hash);
        $this->assertTrue($this->hasher->verify(
            $second->prev_hash,
            $this->storedPayload($second->id),
            (string) $second->hash,
        ));
    }

    public function test_system_context_gana_en_jobs(): void
    {
        Auth::setUser($this->actor(id: 99, email: 'ignored@example.com'));
        $this->context->forSystem('ScrapeExchangeRateJob', 'job-77', 'default', 'job');

        $event = DB::transaction(fn () => $this->recorder->record(
            AuditAction::ExchangeRateScraped,
            new AuditEventData(action: AuditAction::ExchangeRateScraped),
        ));

        $this->assertNotNull($event);
        $this->assertSame('system', $event->actor_type);
        $this->assertNull($event->actor_id);
        $this->assertSame('ScrapeExchangeRateJob', $event->actor_label);
        $this->assertSame('job-77', $event->job_id);
        $this->assertSame('unspecified', $event->reason_code);
        $this->assertSame('default', $event->context['queue']);
        $this->assertSame('job', $event->context['source']);
    }
}
