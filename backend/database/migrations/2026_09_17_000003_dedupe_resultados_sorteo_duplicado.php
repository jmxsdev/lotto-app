<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Deduplicación de resultados pre-H22 (REQ14/D6-a).
 *
 * Agrupa `resultados` por `(juego_id, DATE(fecha_sorteo), hora normalizada)`
 * y conserva UNA fila por sorteo — la más completa en `numeros_ganadores`
 * (desempate: `updated_at` más reciente, luego `id` mayor), fusionando las
 * claves faltantes de las filas descartadas (mismo criterio que
 * `ScrapeResultsJob::dedupeResultadosDelDia`, D6-b).
 *
 * Orden de operaciones para no chocar con el índice único
 * `resultados_juego_fecha_hora_unique`: primero se normaliza la hora en
 * memoria, se eligen los supervivientes, se BORRAN los descartados y solo
 * entonces se actualiza la hora/fusión del superviviente.
 *
 * Idempotente: una segunda ejecución no encuentra grupos duplicados.
 * `down` es no-op documentado: no se puede reconstruir el duplicado borrado.
 */
return new class extends Migration
{
    public function up(): void
    {
        $filas = DB::table('resultados')->orderBy('id')->get();

        // 1) Normalizar hora en memoria y agrupar por sorteo.
        $grupos = [];
        foreach ($filas as $fila) {
            $fecha = substr((string) $fila->fecha_sorteo, 0, 10);
            $hora = $this->normalizarHora((string) $fila->hora_sorteo);

            if ($hora === null) {
                continue; // hora inválida: no entra en la clave de dedupe
            }

            $clave = $fila->juego_id.'|'.$fecha.'|'.$hora;
            $grupos[$clave][] = $fila;
        }

        $aBorrar = [];
        $aActualizar = [];

        foreach ($grupos as $grupo) {
            if (count($grupo) <= 1) {
                continue;
            }

            $superviviente = $this->elegirSuperviviente($grupo);
            $numeros = json_decode($superviviente->numeros_ganadores ?? '{}', true) ?: [];

            foreach ($grupo as $fila) {
                if ($fila->id === $superviviente->id) {
                    continue;
                }

                // Fusiona claves faltantes (D6-a: no se pierde `premios_detalle`).
                $otros = json_decode($fila->numeros_ganadores ?? '{}', true) ?: [];
                foreach ($otros as $clave => $valor) {
                    if (! array_key_exists($clave, $numeros)) {
                        $numeros[$clave] = $valor;
                    }
                }

                $aBorrar[] = $fila->id;
            }

            $cambios = [];
            $horaNormalizada = $this->normalizarHora((string) $superviviente->hora_sorteo);
            if ($horaNormalizada !== null && $horaNormalizada !== $superviviente->hora_sorteo) {
                $cambios['hora_sorteo'] = $horaNormalizada;
            }
            if (json_encode($numeros) !== $superviviente->numeros_ganadores) {
                $cambios['numeros_ganadores'] = json_encode($numeros);
            }

            if ($cambios !== []) {
                $aActualizar[] = ['id' => $superviviente->id, 'cambios' => $cambios];
            }
        }

        // 2) Borrar primero (libera el índice único) y luego actualizar.
        foreach ($aBorrar as $id) {
            DB::table('resultados')->where('id', $id)->delete();
        }

        foreach ($aActualizar as $actualizacion) {
            DB::table('resultados')
                ->where('id', $actualizacion['id'])
                ->update($actualizacion['cambios']);
        }
    }

    public function down(): void
    {
        // No reversible: las filas descartadas se borraron y su contenido se
        // fusionó en el superviviente; reconstruir el duplicado no es fiable.
    }

    /**
     * Misma puntuación que `ScrapeResultsJob::dedupeResultadosDelDia`:
     * más claves en `numeros_ganadores`, `updated_at` más reciente, `id` mayor.
     *
     * @param  array<int, object>  $grupo
     */
    private function elegirSuperviviente(array $grupo): object
    {
        $mejor = null;
        $mejorPuntaje = -1;

        foreach ($grupo as $fila) {
            $numeros = json_decode($fila->numeros_ganadores ?? '{}', true) ?: [];
            $puntaje = (count($numeros) * 1_000_000_000)
                + ((int) strtotime((string) $fila->updated_at))
                + (int) $fila->id;

            if ($puntaje > $mejorPuntaje) {
                $mejorPuntaje = $puntaje;
                $mejor = $fila;
            }
        }

        return $mejor;
    }

    /**
     * Normaliza "H:i" / "H:i:s" / "hh:mm AM|PM" a "H:i" (America/Caracas).
     */
    private function normalizarHora(string $hora): ?string
    {
        if (trim($hora) === '') {
            return null;
        }

        if (preg_match('/[AP]M/i', $hora)) {
            try {
                return Carbon::parse(trim($hora), 'America/Caracas')->format('H:i');
            } catch (Throwable) {
                return null;
            }
        }

        if (preg_match('/^\d{2}:\d{2}/', $hora, $match) === 1) {
            return $match[0];
        }

        return null;
    }
};
