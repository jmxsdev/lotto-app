<?php

namespace App\Http\Middleware;

use App\Audit\AuditContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Captures the transport metadata of an API request into the scoped audit
 * context and propagates `X-Request-Id`.
 *
 * It deliberately does not resolve the actor and writes no audit event: Sanctum
 * has not authenticated the request yet, so the actor is resolved lazily by the
 * recorder. The context is reset in a `finally` block so state never leaks
 * between requests or queued jobs.
 */
class CaptureAuditContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $context = app(AuditContext::class);
        $requestId = null;

        try {
            $context->captureRequest($request);
            $requestId = $context->requestId();

            $response = $next($request);
        } finally {
            $context->reset();
        }

        if ($requestId !== null && ! $response->headers->has('X-Request-Id')) {
            $response->headers->set('X-Request-Id', $requestId);
        }

        return $response;
    }
}
