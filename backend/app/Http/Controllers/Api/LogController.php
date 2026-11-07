<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Log;
use Carbon\CarbonImmutable;
use Closure;
use DateTimeImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Operational log view (`/logs`).
 *
 * `logs` is not the audit trail: it only exposes the actions listed in
 * `config('audit.logs.operational_actions')`. The audience is
 * `permission:view_logs` (master via matrix, super_master via bypass); the
 * route middleware enforces it.
 */
class LogController extends Controller
{
    /**
     * @var array<int, string>
     */
    private const ALLOWED_FILTERS = [
        'action',
        'from',
        'to',
        'per_page',
        'page',
    ];

    private const DEFAULT_PER_PAGE = 50;

    private const MAX_PER_PAGE = 100;

    public function index(Request $request): JsonResponse
    {
        $filters = $this->validatedFilters($request);

        $query = Log::with('user')
            ->whereIn('action', (array) config('audit.logs.operational_actions', []));

        if (isset($filters['action']) && $filters['action'] !== '') {
            $query->where('action', $filters['action']);
        }

        if (isset($filters['from']) && $filters['from'] !== '') {
            $query->where('created_at', '>=', $this->boundary($filters['from'], endOfDay: false));
        }

        if (isset($filters['to']) && $filters['to'] !== '') {
            $query->where('created_at', '<=', $this->boundary($filters['to'], endOfDay: true));
        }

        $paginator = $query
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($this->perPage($filters['per_page'] ?? null))
            ->withQueryString();

        return response()->json($paginator);
    }

    /**
     * Reject unknown query keys and validate the operational filters.
     *
     * @return array<string, mixed>
     */
    private function validatedFilters(Request $request): array
    {
        $unknown = array_values(array_diff(array_keys($request->query()), self::ALLOWED_FILTERS));

        if ($unknown !== []) {
            throw ValidationException::withMessages([
                'filters' => ['Filtros desconocidos: '.implode(', ', $unknown).'.'],
            ]);
        }

        return $request->validate([
            'action' => ['nullable', 'string', 'max:64'],
            'from' => ['nullable', $this->dateRule()],
            'to' => ['nullable', $this->dateRule()],
            'per_page' => ['nullable', 'integer', 'min:1'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
    }

    /**
     * Accept `Y-m-d` or ISO-8601; reject free text and relative dates.
     */
    private function dateRule(): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value) || $value === '') {
                $fail("El filtro {$attribute} debe ser una fecha (Y-m-d) o ISO-8601.");

                return;
            }

            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
                $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

                if ($parsed === false || $parsed->format('Y-m-d') !== $value) {
                    $fail("El filtro {$attribute} debe ser una fecha (Y-m-d) o ISO-8601.");
                }

                return;
            }

            $iso = '/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}:?\d{2})?$/';

            if (preg_match($iso, $value) !== 1) {
                $fail("El filtro {$attribute} debe ser una fecha (Y-m-d) o ISO-8601.");

                return;
            }

            try {
                new DateTimeImmutable($value);
            } catch (\Throwable) {
                $fail("El filtro {$attribute} debe ser una fecha (Y-m-d) o ISO-8601.");
            }
        };
    }

    /**
     * Convert a filter boundary to UTC for comparison against `created_at`.
     *
     * A plain `Y-m-d` date is read in the app timezone (`America/Caracas`):
     * `from` starts the day, `to` ends it. ISO-8601 values keep their own
     * offset and are only normalized to UTC.
     */
    private function boundary(string $value, bool $endOfDay): CarbonImmutable
    {
        $timezone = (string) config('app.timezone', 'America/Caracas');

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, $timezone);

            return ($endOfDay ? $date->endOfDay() : $date->startOfDay())->utc();
        }

        return CarbonImmutable::parse($value, $timezone)->utc();
    }

    /**
     * Default 50 per page, clamped to a maximum of 100.
     */
    private function perPage(mixed $value): int
    {
        if ($value === null || $value === '') {
            return self::DEFAULT_PER_PAGE;
        }

        $perPage = (int) $value;

        if ($perPage < 1) {
            return self::DEFAULT_PER_PAGE;
        }

        return min($perPage, self::MAX_PER_PAGE);
    }
}
