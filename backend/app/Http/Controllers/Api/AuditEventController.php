<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AuditQueryService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Lectura paginada del histórico de auditoría.
 *
 * La autorización (`permission:view_audit`) vive en `routes/api.php`; aquí solo
 * se validan filtros, se rechazan claves desconocidas y se delega el scope en
 * `AuditQueryService`.
 */
class AuditEventController extends Controller
{
    /**
     * @var array<int, string>
     */
    private const ALLOWED_FILTERS = [
        'action',
        'domain',
        'actor_id',
        'actor_type',
        'entity_type',
        'entity_id',
        'from',
        'to',
        'request_id',
        'reason_code',
        'per_page',
        'cursor',
    ];

    public function index(Request $request, AuditQueryService $query): JsonResponse
    {
        $unknown = array_values(array_diff(array_keys($request->query()), self::ALLOWED_FILTERS));

        if ($unknown !== []) {
            throw ValidationException::withMessages([
                'filters' => ['Filtros desconocidos: '.implode(', ', $unknown).'.'],
            ]);
        }

        $filters = $request->validate([
            'action' => ['nullable', 'string', 'regex:/^[a-z_]+(\.[a-z_]+)+$/'],
            'domain' => ['nullable', 'string', 'regex:/^[a-z_]+$/'],
            'actor_id' => ['nullable', 'integer'],
            'actor_type' => ['nullable', 'string', 'in:user,system,device'],
            'entity_type' => ['nullable', 'string', 'max:32'],
            'entity_id' => ['nullable', 'integer'],
            'from' => ['nullable', $this->dateRule()],
            'to' => ['nullable', $this->dateRule()],
            'request_id' => ['nullable', 'string', 'max:64'],
            'reason_code' => ['nullable', 'string', 'max:64'],
            'per_page' => ['nullable', 'integer', 'min:1'],
            'cursor' => ['nullable', 'string'],
        ]);

        return response()->json($query->page(
            $request->user(),
            $filters,
            (int) ($filters['per_page'] ?? AuditQueryService::DEFAULT_PER_PAGE),
            isset($filters['cursor']) ? (string) $filters['cursor'] : null,
        ));
    }

    /**
     * Acepta `Y-m-d` o ISO-8601; rechaza texto libre y fechas relativas.
     */
    private function dateRule(): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value)
                || (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1 && strtotime($value) === false)) {
                $fail("El filtro {$attribute} debe ser una fecha (Y-m-d) o ISO-8601.");
            }
        };
    }
}
