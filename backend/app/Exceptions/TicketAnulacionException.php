<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Falla de negocio de la anulación de un ticket o de una apuesta suelta.
 *
 * El status HTTP y el código de motivo viajan con la excepción para que los
 * controllers se limiten a mapear `{success: false, message}` y no re-deriven
 * reglas ni textos.
 */
final class TicketAnulacionException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $status = 422,
        public readonly ?string $motivo = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /** El ticket o la apuesta no existe (o ya está soft-deleted). */
    public static function noEncontrado(string $message = 'Ticket no encontrado.'): self
    {
        return new self($message, 404);
    }

    /** El usuario no pertenece al árbol de la venta (`sin_ownership`). */
    public static function noAutorizado(string $message = 'No autorizado.', ?string $motivo = null): self
    {
        return new self($message, 403, $motivo);
    }

    /** La operación no cumple una regla de negocio (422). */
    public static function rechazado(string $message, ?string $motivo = null): self
    {
        return new self($message, 422, $motivo);
    }
}
