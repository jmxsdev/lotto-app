<?php

namespace App\Audit;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Scoped per-request audit context.
 *
 * Captures transport metadata without touching the authenticated user. The
 * actor is resolved lazily at record time because Sanctum has not
 * authenticated the request when the middleware runs. The container binds this
 * class as a scoped singleton, so state never leaks between requests; jobs and
 * commands call `reset()` in a `finally` block.
 */
final class AuditContext
{
    private const REQUEST_ID_PATTERN = '/^[A-Za-z0-9._-]{8,64}$/';

    private const USER_AGENT_MAX = 512;

    private ?string $requestId = null;

    private ?string $ip = null;

    private ?string $userAgent = null;

    private ?string $route = null;

    private ?string $method = null;

    private ?string $client = null;

    private ?string $source = null;

    private ?string $jobId = null;

    private ?string $queue = null;

    private ?string $reasonCode = null;

    private ?string $reasonText = null;

    private ?array $actor = null;

    private bool $actorResolved = false;

    private ?array $system = null;

    /**
     * Capture request metadata. Never resolves the actor.
     */
    public function captureRequest(Request $request): void
    {
        $this->reset();

        $this->requestId = $this->normalizeRequestId($request->header('X-Request-Id'));
        $this->ip = $request->ip();
        $this->userAgent = $this->truncate($request->userAgent(), self::USER_AGENT_MAX);
        $this->method = $request->getMethod();

        $route = $request->route();
        $uri = $route instanceof Route ? $route->uri() : null;
        $this->route = $uri !== null ? $this->formatRoute($uri) : null;

        $this->client = $this->detectClient($request);
        $this->source = 'api';
    }

    public function requestId(): ?string
    {
        return $this->requestId;
    }

    public function ip(): ?string
    {
        return $this->ip;
    }

    public function userAgent(): ?string
    {
        return $this->userAgent;
    }

    public function route(): ?string
    {
        return $this->route;
    }

    public function method(): ?string
    {
        return $this->method;
    }

    public function client(): ?string
    {
        return $this->client;
    }

    public function source(): ?string
    {
        return $this->source;
    }

    public function jobId(): ?string
    {
        return $this->jobId;
    }

    public function queue(): ?string
    {
        return $this->queue;
    }

    /**
     * Resolve the actor once and cache it.
     *
     * When `forSystem()` was called, the system snapshot wins over the HTTP
     * user. Otherwise this resolves the authenticated user lazily.
     *
     * @return array{type: string, id: int|null, label: string|null, roles: array<int, string>}|null
     */
    public function actor(): ?array
    {
        if ($this->system !== null) {
            return $this->system;
        }

        if (! $this->actorResolved) {
            $this->resolveActor();
        }

        return $this->actor;
    }

    /**
     * @return array{type: string, id: int|null, label: string|null, roles: array<int, string>}|null
     */
    public function resolveActor(): ?array
    {
        if ($this->actorResolved) {
            return $this->actor;
        }

        $this->actor = $this->snapshotActor(Auth::user());
        $this->actorResolved = true;

        return $this->actor;
    }

    /**
     * Explicitly set the actor. Used by flows where the identity is known
     * before authentication succeeds, such as a failed login.
     *
     * @param  array<int, string>  $roles
     */
    public function setActor(?Authenticatable $user, ?string $label = null, array $roles = []): void
    {
        $this->system = null;
        $this->actor = $this->snapshotActor($user, $label, $roles);
        $this->actorResolved = true;
    }

    /**
     * Switch the context to a system actor for a job or command.
     */
    public function forSystem(string $label, ?string $jobId = null, ?string $queue = null, ?string $source = null): void
    {
        $label = trim($label);

        $this->actor = null;
        $this->actorResolved = false;
        $this->system = [
            'type' => AuditActorType::System->value,
            'id' => null,
            'label' => $label !== '' ? $label : null,
            'roles' => [],
        ];
        $this->jobId = $jobId;
        $this->queue = $queue;
        $this->source = $source ?? 'job';
    }

    /**
     * @return array{type: string, id: null, label: string|null, roles: array<int, string>}|null
     */
    public function system(): ?array
    {
        return $this->system;
    }

    public function isSystem(): bool
    {
        return $this->system !== null;
    }

    /**
     * Store the reason for the next recorded event.
     */
    public function withReason(?AuditReason $code, ?string $text): void
    {
        $this->reasonCode = $code?->value;

        $text = $text !== null ? trim($text) : null;
        $max = max(0, (int) config('audit.reasons.text_max', 500));
        $truncated = $text !== null && $text !== '' ? mb_substr($text, 0, $max) : null;

        $this->reasonText = $truncated !== null && $truncated !== '' ? $truncated : null;
    }

    /**
     * @return array{code: string|null, text: string|null}
     */
    public function reason(): array
    {
        return [
            'code' => $this->reasonCode,
            'text' => $this->reasonText,
        ];
    }

    /**
     * Clear all per-request state. Safe to call between jobs in a worker.
     */
    public function reset(): void
    {
        $this->requestId = null;
        $this->ip = null;
        $this->userAgent = null;
        $this->route = null;
        $this->method = null;
        $this->client = null;
        $this->source = null;
        $this->jobId = null;
        $this->queue = null;
        $this->reasonCode = null;
        $this->reasonText = null;
        $this->actor = null;
        $this->actorResolved = false;
        $this->system = null;
    }

    /**
     * Transport and reason fields the recorder copies into the envelope.
     *
     * `request_id` and `job_id` travel here for convenience; the recorder moves
     * them to their indexed columns and strips them from the JSON context.
     *
     * @return array<string, string>
     */
    public function toContext(): array
    {
        return array_filter([
            'route' => $this->route,
            'method' => $this->method,
            'client' => $this->client,
            'source' => $this->source,
            'request_id' => $this->requestId,
            'job_id' => $this->jobId,
            'queue' => $this->queue,
            'reason_code' => $this->reasonCode,
            'reason_text' => $this->reasonText,
        ], static fn (?string $value): bool => $value !== null && $value !== '');
    }

    /**
     * @param  array<int, string>  $roles
     * @return array{type: string, id: int|null, label: string|null, roles: array<int, string>}
     */
    private function snapshotActor(?Authenticatable $user, ?string $label = null, array $roles = []): array
    {
        if ($user === null) {
            return [
                'type' => AuditActorType::User->value,
                'id' => null,
                'label' => $label,
                'roles' => array_values($roles),
            ];
        }

        if ($roles === [] && method_exists($user, 'getRoleNames')) {
            $names = $user->getRoleNames();
            $roles = $names instanceof Collection ? $names->all() : (array) $names;
        }

        $identifier = $user->getAuthIdentifier();

        return [
            'type' => AuditActorType::User->value,
            'id' => is_numeric($identifier) ? (int) $identifier : null,
            'label' => $label ?? (data_get($user, 'email') ?? data_get($user, 'name')),
            'roles' => array_values(array_map('strval', $roles)),
        ];
    }

    private function formatRoute(string $uri): ?string
    {
        $uri = trim($uri);

        if ($uri === '') {
            return null;
        }

        return trim($this->method.' /'.ltrim($uri, '/'));
    }

    private function normalizeRequestId(?string $header): string
    {
        $candidate = $header !== null ? trim($header) : '';

        if ($candidate !== '' && preg_match(self::REQUEST_ID_PATTERN, $candidate) === 1) {
            return $candidate;
        }

        return Str::uuid()->toString();
    }

    private function detectClient(Request $request): string
    {
        if ($request->headers->has('X-Panel')) {
            return 'panel';
        }

        if ($request->headers->has('X-Taquilla')) {
            return 'taquilla';
        }

        return 'api';
    }

    private function truncate(?string $value, int $max): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        return mb_substr($value, 0, $max);
    }
}
