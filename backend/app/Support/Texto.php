<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Normalización compartida de texto para validación y liquidación de apuestas.
 *
 * D3 del diseño motor-premios: `Str::ascii(trim($v))` + `mb_strtolower` hace que
 * "Delfín" ≡ "Delfin" y "CAIMÁN" ≡ "caiman". Se usa en ambos lados de la
 * comparación (apuesta vs resultado) para cubrir el mismo universo en
 * validación y pago (H13, N2, N10).
 */
class Texto
{
    public static function normalizar(?string $valor): string
    {
        return mb_strtolower(Str::ascii(trim((string) $valor)));
    }
}
