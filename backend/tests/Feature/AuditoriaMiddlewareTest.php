<?php

namespace Tests\Feature;

use App\Audit\AuditContext;
use App\Http\Middleware\CaptureAuditContext;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class AuditoriaMiddlewareTest extends TestCase
{
    private function request(
        array $headers = [],
        string $method = 'PUT',
        string $uri = '/api/v1/bancas/5',
    ): Request {
        $server = ['REMOTE_ADDR' => '190.0.0.1'];

        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        $request = Request::create($uri, $method, [], [], [], $server);
        $request->setRouteResolver(static fn () => new Route([$method], 'api/v1/bancas/{banca}', []));

        return $request;
    }

    /**
     * @return array{0: Response, 1: array<string, mixed>}
     */
    private function handle(Request $request): array
    {
        $response = (new CaptureAuditContext)->handle($request, static function (Request $request): Response {
            $context = app(AuditContext::class);

            return response()->json([
                'request_id' => $context->requestId(),
                'ip' => $context->ip(),
                'user_agent' => $context->userAgent(),
                'route' => $context->route(),
                'method' => $context->method(),
                'client' => $context->client(),
                'source' => $context->source(),
            ]);
        });

        return [$response, json_decode((string) $response->getContent(), true)];
    }

    public function test_usa_el_request_id_del_header_y_lo_propaga(): void
    {
        [$response, $payload] = $this->handle($this->request(['X-Request-Id' => 'req-12345678']));

        $this->assertSame('req-12345678', $response->headers->get('X-Request-Id'));
        $this->assertSame('req-12345678', $payload['request_id']);
    }

    public function test_genera_un_uuid_si_falta_el_header(): void
    {
        [$response, $payload] = $this->handle($this->request());

        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            (string) $payload['request_id'],
        );
        $this->assertSame($payload['request_id'], $response->headers->get('X-Request-Id'));
    }

    public function test_genera_un_uuid_si_el_header_es_invalido(): void
    {
        [, $payload] = $this->handle($this->request(['X-Request-Id' => 'bad id!']));

        $this->assertNotSame('bad id!', $payload['request_id']);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            (string) $payload['request_id'],
        );
    }

    public function test_captura_los_metadatos_de_la_peticion(): void
    {
        [, $payload] = $this->handle($this->request([
            'User-Agent' => 'phpunit-agent',
            'X-Panel' => '1',
        ]));

        $this->assertSame('190.0.0.1', $payload['ip']);
        $this->assertSame('phpunit-agent', $payload['user_agent']);
        $this->assertSame('PUT /api/v1/bancas/{banca}', $payload['route']);
        $this->assertSame('PUT', $payload['method']);
        $this->assertSame('panel', $payload['client']);
        $this->assertSame('api', $payload['source']);
    }

    public function test_detecta_el_cliente_taquilla(): void
    {
        [, $payload] = $this->handle($this->request(['X-Taquilla' => '1']));

        $this->assertSame('taquilla', $payload['client']);
    }

    public function test_resetea_el_contexto_al_terminar(): void
    {
        $context = app(AuditContext::class);

        $this->handle($this->request(['X-Request-Id' => 'req-abcdefgh']));

        $this->assertNull($context->requestId());
        $this->assertNull($context->ip());
        $this->assertNull($context->userAgent());
        $this->assertSame([], $context->toContext());
    }

    public function test_respeta_un_x_request_id_ya_presente_en_la_respuesta(): void
    {
        $response = (new CaptureAuditContext)->handle(
            $this->request(['X-Request-Id' => 'req-12345678']),
            static fn (Request $request): Response => response()->json(['ok' => true])->header('X-Request-Id', 'upstream-id'),
        );

        $this->assertSame('upstream-id', $response->headers->get('X-Request-Id'));
    }
}
