<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Throwable;

/**
 * Fail-closed audit write failure. The business transaction must roll back and
 * the client must receive a 500, never a silent success.
 */
class AuditWriteException extends RuntimeException
{
    public function __construct(
        string $message = 'Unable to persist the audit event.',
        int $code = 0,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => 'No se pudo registrar la auditoría.',
        ], 500);
    }
}
