<?php

namespace App\Services;

use App\Models\Configuracion;

/**
 * Acceso a settings en la tabla `configuraciones` (D5, design §5).
 *
 * Clave global `apuestas.vencimiento_sin_resultado` con `{"horas": 24}` y
 * `banca_id = null`. Sin fila → fallback 24 h (no requiere migración de
 * datos). El patrón de settings ya existía "dormido" en el sistema (modelo
 * `Configuracion` + tabla); este servicio lo activa para la ventana de
 * vencimiento sin resultado (REQ13).
 */
class ConfiguracionService
{
    public const CLAVE_VENCIMIENTO = 'apuestas.vencimiento_sin_resultado';

    private const HORAS_DEFAULT = 24;

    /**
     * Leer el value (array JSON) de una clave global.
     *
     * @return array<string, mixed>|null
     */
    public function get(string $key): ?array
    {
        $fila = Configuracion::where('key', $key)
            ->whereNull('banca_id')
            ->first();

        return $fila?->value;
    }

    /**
     * Escribir (crear o actualizar) una clave global. El `key` es único.
     *
     * @param  array<string, mixed>  $value
     */
    public function set(string $key, array $value, ?string $descripcion = null): Configuracion
    {
        return Configuracion::updateOrCreate(
            ['key' => $key, 'banca_id' => null],
            ['value' => $value, 'description' => $descripcion]
        );
    }

    /**
     * Ventana de vencimiento sin resultado: `{"horas": N}` con fallback 24 h.
     *
     * @return array{horas: int}
     */
    public function ventanaVencimientoSinResultado(): array
    {
        return ['horas' => $this->horasVencimientoSinResultado()];
    }

    /**
     * Horas de la ventana (default 24 h si la fila no existe o viene sin
     * la clave `horas`). Mínimo 1 h por seguridad operativa.
     */
    public function horasVencimientoSinResultado(): int
    {
        $value = $this->get(self::CLAVE_VENCIMIENTO);
        $horas = (int) ($value['horas'] ?? self::HORAS_DEFAULT);

        return max(1, $horas);
    }

    public function setVentanaVencimiento(int $horas): Configuracion
    {
        return $this->set(
            self::CLAVE_VENCIMIENTO,
            ['horas' => max(1, $horas)],
            'Ventana de vencimiento de apuestas sin resultado (horas).'
        );
    }
}
