<?php

namespace Tests\Unit\Audit;

use App\Audit\AuditContext;
use App\Audit\AuditReason;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class AuditContextTest extends TestCase
{
    protected function tearDown(): void
    {
        Auth::forgetGuards();

        parent::tearDown();
    }

    private function makeRequest(
        array $headers = [],
        string $method = 'PUT',
        string $uri = '/api/v1/bancas/5',
        string $routeUri = 'api/v1/bancas/{banca}',
    ): Request {
        $server = ['REMOTE_ADDR' => '190.0.0.1'];

        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        $request = Request::create($uri, $method, [], [], [], $server);
        $route = new Route([$method], $routeUri, []);
        $request->setRouteResolver(static fn () => $route);

        return $request;
    }

    /**
     * @param  array<int, string>  $roles
     */
    private function fakeActor(int $id = 7, string $email = 'jdoe@example.com', array $roles = ['master']): Authenticatable
    {
        return new class($id, $email, $roles) implements Authenticatable
        {
            public function __construct(
                public int $id,
                public string $email,
                public array $roles,
            ) {}

            public function getAuthIdentifierName(): string
            {
                return 'id';
            }

            public function getAuthIdentifier(): mixed
            {
                return $this->id;
            }

            public function getAuthPasswordName(): string
            {
                return 'password';
            }

            public function getAuthPassword(): string
            {
                return '';
            }

            public function getRememberToken(): ?string
            {
                return null;
            }

            public function setRememberToken($value): void
            {
                //
            }

            public function getRememberTokenName(): string
            {
                return 'remember_token';
            }

            public function getRoleNames()
            {
                return collect($this->roles);
            }
        };
    }

    public function test_capture_request_guarda_metadatos_y_request_id(): void
    {
        $context = new AuditContext;

        $context->captureRequest($this->makeRequest([
            'X-Request-Id' => 'req-12345678',
            'X-Panel' => '1',
            'User-Agent' => 'phpunit-agent',
        ]));

        $this->assertSame('req-12345678', $context->requestId());
        $this->assertSame('190.0.0.1', $context->ip());
        $this->assertSame('phpunit-agent', $context->userAgent());
        $this->assertSame('PUT', $context->method());
        $this->assertSame('PUT /api/v1/bancas/{banca}', $context->route());
        $this->assertSame('panel', $context->client());
        $this->assertSame('api', $context->source());
    }

    public function test_capture_request_detecta_cliente_taquilla(): void
    {
        $context = new AuditContext;

        $context->captureRequest($this->makeRequest(['X-Taquilla' => '1']));

        $this->assertSame('taquilla', $context->client());
    }

    public function test_capture_request_sin_cabecera_de_cliente_es_api(): void
    {
        $context = new AuditContext;

        $context->captureRequest($this->makeRequest());

        $this->assertSame('api', $context->client());
    }

    public function test_capture_request_genera_uuid_si_no_hay_header(): void
    {
        $context = new AuditContext;

        $context->captureRequest($this->makeRequest());

        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            (string) $context->requestId(),
        );
    }

    public function test_capture_request_ignora_un_request_id_invalido(): void
    {
        $context = new AuditContext;

        $context->captureRequest($this->makeRequest(['X-Request-Id' => 'bad id!']));

        $this->assertNotSame('bad id!', $context->requestId());
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            (string) $context->requestId(),
        );
    }

    public function test_el_actor_se_resuelve_al_leer_y_no_al_capturar(): void
    {
        $context = new AuditContext;

        // Se captura sin usuario autenticado: si captureRequest resolviera al
        // actor, el snapshot quedaría vacío para siempre.
        $context->captureRequest($this->makeRequest());

        Auth::setUser($this->fakeActor());

        $actor = $context->actor();

        $this->assertSame('user', $actor['type']);
        $this->assertSame(7, $actor['id']);
        $this->assertSame('jdoe@example.com', $actor['label']);
        $this->assertSame(['master'], $actor['roles']);
    }

    public function test_el_actor_sin_usuario_devuelve_snapshot_vacio(): void
    {
        $context = new AuditContext;

        $this->assertSame([
            'type' => 'user',
            'id' => null,
            'label' => null,
            'roles' => [],
        ], $context->actor());
    }

    public function test_el_actor_se_cachea_en_la_primera_lectura(): void
    {
        $context = new AuditContext;

        Auth::setUser($this->fakeActor());

        $first = $context->actor();

        Auth::setUser($this->fakeActor(id: 99, email: 'other@example.com'));

        $this->assertSame($first, $context->actor());
    }

    public function test_for_system_fija_actor_job_y_queue(): void
    {
        $context = new AuditContext;

        $context->forSystem('ScrapeExchangeRateJob', 'job-1', 'default', 'job');

        $actor = $context->actor();

        $this->assertSame('system', $actor['type']);
        $this->assertSame('ScrapeExchangeRateJob', $actor['label']);
        $this->assertNull($actor['id']);
        $this->assertSame([], $actor['roles']);
        $this->assertTrue($context->isSystem());
        $this->assertSame($actor, $context->system());
        $this->assertSame('job-1', $context->jobId());
        $this->assertSame('default', $context->queue());
        $this->assertSame('job', $context->source());
    }

    public function test_for_system_usa_job_como_source_por_defecto(): void
    {
        $context = new AuditContext;

        $context->forSystem('Job');

        $this->assertSame('job', $context->source());
    }

    public function test_with_reason_guarda_codigo_y_capa_el_texto(): void
    {
        $context = new AuditContext;

        $context->withReason(AuditReason::DataCorrection, str_repeat('x', 700));

        $reason = $context->reason();

        $this->assertSame('data_correction', $reason['code']);
        $this->assertSame(500, mb_strlen((string) $reason['text']));
    }

    public function test_with_reason_limpia_texto_vacio(): void
    {
        $context = new AuditContext;

        $context->withReason(AuditReason::Other, '   ');

        $this->assertSame('other', $context->reason()['code']);
        $this->assertNull($context->reason()['text']);
    }

    public function test_to_context_expone_request_sistema_y_motivo(): void
    {
        $context = new AuditContext;

        $context->captureRequest($this->makeRequest([
            'X-Request-Id' => 'req-abcdefgh',
            'X-Panel' => '1',
        ]));
        $context->forSystem('Job', 'job-1', 'default', 'job');
        $context->withReason(AuditReason::DataCorrection, 'Correction');

        $this->assertSame([
            'route' => 'PUT /api/v1/bancas/{banca}',
            'method' => 'PUT',
            'client' => 'panel',
            'source' => 'job',
            'request_id' => 'req-abcdefgh',
            'job_id' => 'job-1',
            'queue' => 'default',
            'reason_code' => 'data_correction',
            'reason_text' => 'Correction',
        ], $context->toContext());
    }

    public function test_reset_limpia_todo_el_estado(): void
    {
        $context = new AuditContext;

        $context->captureRequest($this->makeRequest(['X-Request-Id' => 'req-abcdefgh']));
        $context->forSystem('Job', 'job-1', 'default', 'job');
        $context->withReason(AuditReason::DataCorrection, 'Correction');

        $context->reset();

        $this->assertNull($context->requestId());
        $this->assertNull($context->ip());
        $this->assertNull($context->userAgent());
        $this->assertNull($context->route());
        $this->assertNull($context->method());
        $this->assertNull($context->client());
        $this->assertNull($context->source());
        $this->assertNull($context->jobId());
        $this->assertNull($context->queue());
        $this->assertFalse($context->isSystem());
        $this->assertSame([], $context->toContext());
        $this->assertSame([
            'type' => 'user',
            'id' => null,
            'label' => null,
            'roles' => [],
        ], $context->actor());
    }

    public function test_set_actor_permite_etiqueta_sin_usuario(): void
    {
        $context = new AuditContext;

        $context->setActor(null, 'j***@example.com', []);

        $actor = $context->actor();

        $this->assertSame('user', $actor['type']);
        $this->assertNull($actor['id']);
        $this->assertSame('j***@example.com', $actor['label']);
        $this->assertSame([], $actor['roles']);
    }
}
