<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Invalid audit payload (action mismatch, missing required reason, bad reason
 * code or malformed batch input). Rendered as a 422 before anything is written.
 */
class AuditValidationException extends RuntimeException
{
    /**
     * @var array<string, array<int, string>>
     */
    private array $errors = [];

    /**
     * @param  array<string, array<int, string>>  $errors
     */
    public static function forField(string $field, string $message, array $errors = []): self
    {
        $exception = new self($message);
        $exception->errors = $errors !== [] ? $errors : [$field => [$message]];

        return $exception;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage() !== '' ? $this->getMessage() : 'Los datos de auditoría no son válidos.',
            'errors' => $this->errors,
        ], 422);
    }
}
