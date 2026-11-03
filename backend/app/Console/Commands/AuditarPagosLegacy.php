<?php

namespace App\Console\Commands;

use App\Models\Apuesta;
use App\Models\Log;
use App\Models\Pago;
use App\Models\Ticket;
use App\Services\PremiosLiquidador;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Auditoría gated de datos legacy de pagos (WU5, design §10).
 *
 * Off por defecto: sin `--apply`/`--revert` solo ejecuta las consultas C1-C5 y
 * reporta por taquilla (dry-run, cero escrituras de base de datos). Cualquier
 * mutación exige `--apply`/`--revert` MÁS `--ids` explícitos; nunca hace un
 * update en bloque.
 *
 * Garantías del `--apply`:
 * - recalcula el premio con `PremiosLiquidador` (mismo motor que el egreso);
 * - transiciona `pagada` -> `ganadora`/`perdida` (nunca `pendiente`);
 * - restaura `detalle_apuestas.premio_ganado(_usd)`;
 * - recomputa el estado del ticket (`anulada`/`ganador`/`pagada`);
 * - conserva las filas de `pagos` (evidencia de caja);
 * - escribe UN log `auditoria.pago_legacy` por apuesta con old/new values;
 * - nunca toca `pagos.amount_*`, `cierres_caja` ni `comisiones`;
 * - un ticket multilínea que no cuadra contra `tickets.premio_total_*` se
 *   deriva a revisión manual sin escribir.
 *
 * `--revert --ids` lee el último log por apuesta y restaura `old_values`.
 */
final class AuditarPagosLegacy extends Command
{
    private const ACTION = 'auditoria.pago_legacy';

    protected $signature = 'pagos:auditar-legacy
        {--apply : Aplica la corrección SOLO a los ids explícitos de --ids}
        {--revert : Revierte los ids explícitos desde el último log de auditoría}
        {--ids= : IDs de apuesta separados por coma (obligatorio con --apply/--revert)}
        {--csv= : Ruta opcional del CSV del dry-run}
        {--dry-run : Fuerza el modo dry-run (default)}';

    protected $description = 'Auditoría legacy de pagos: dry-run C1-C5 por taquilla; --apply/--revert solo con ids explícitos';

    public function __construct(
        private readonly PremiosLiquidador $liquidador,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $revert = (bool) $this->option('revert');
        $dryRun = (bool) $this->option('dry-run');

        if ($apply && $revert) {
            $this->error('--apply y --revert son mutuamente excluyentes.');

            return self::FAILURE;
        }

        if ($dryRun && ($apply || $revert)) {
            $this->error('--dry-run no se combina con --apply/--revert.');

            return self::FAILURE;
        }

        if (! $apply && ! $revert) {
            return $this->dryRun();
        }

        $ids = $this->parseIds($this->option('ids'));

        if ($ids === null) {
            $this->error('--ids contiene valores inválidos; usa ids numéricos separados por coma.');

            return self::FAILURE;
        }

        if ($ids === []) {
            $this->error('--apply/--revert exige --ids=1,2,... con ids explícitos; no se hace update en bloque.');

            return self::FAILURE;
        }

        return $apply ? $this->aplicar($ids) : $this->revertir($ids);
    }

    // ------------------------------------------------------------------
    // Dry-run
    // ------------------------------------------------------------------

    private function dryRun(): int
    {
        $c1c2 = $this->consultaC1C2();
        $c3c4 = $this->consultaC3C4();
        $c5 = $this->consultaC5();

        $grupos = [];
        $csv = [];

        foreach ($c1c2 as $row) {
            $taquillaId = (int) $row->taquilla_id;
            $taquilla = (string) ($row->taquilla ?? 'N/D');
            $this->inicializarGrupo($grupos, $taquillaId, $taquilla);

            $detalle = sprintf(
                'apuesta=%d ticket=%s estado=%s premio=%s/%s egresos=%d ingresos=%d devoluciones=%d%s',
                $row->apuesta_id,
                $row->ticket_id ?? 'null',
                $row->estado,
                $this->num($row->premio_ganado),
                $this->num($row->premio_ganado_usd),
                (int) $row->egresos,
                (int) $row->ingresos,
                (int) $row->devoluciones,
                $row->deleted_at ? ' soft_deleted' : ''
            );

            $grupos[$taquillaId]['C1/C2'][] = $detalle;
            $csv[] = ['C1/C2', $taquillaId, $taquilla, $row->apuesta_id, $row->ticket_id, $detalle];
        }

        foreach ($c3c4 as $row) {
            $taquillaId = (int) $row->taquilla_id;
            $taquilla = (string) ($row->taquilla ?? 'N/D');
            $this->inicializarGrupo($grupos, $taquillaId, $taquilla);

            $base = sprintf(
                'ticket=%d code=%s lineas_vivas=%d pagadas_sin_egreso=%d premio_total=%s/%s',
                $row->ticket_id,
                $row->ticket_code,
                (int) $row->lineas_vivas,
                (int) $row->lineas_pagadas_sin_egreso,
                $this->num($row->premio_total_bs),
                $this->num($row->premio_total_usd)
            );

            if ((int) $row->lineas_pagadas_sin_egreso > 0) {
                $grupos[$taquillaId]['C3'][] = 'C3 '.$base;
                $csv[] = ['C3', $taquillaId, $taquilla, null, $row->ticket_id, 'C3 '.$base];
            }

            if ((int) $row->lineas_vivas === 0) {
                $grupos[$taquillaId]['C4'][] = 'C4 '.$base;
                $csv[] = ['C4', $taquillaId, $taquilla, null, $row->ticket_id, 'C4 '.$base];
            }
        }

        foreach ($c5 as $row) {
            $taquillaId = (int) $row->taquilla_id;
            $taquilla = (string) ($row->taquilla ?? 'N/D');
            $this->inicializarGrupo($grupos, $taquillaId, $taquilla);

            $detalle = sprintf('apuesta=%d ingresos=%d (venta duplicada)', $row->apuesta_id, (int) $row->ingresos);
            $grupos[$taquillaId]['C5'][] = $detalle;
            $csv[] = ['C5', $taquillaId, $taquilla, $row->apuesta_id, null, $detalle];
        }

        $total = count($csv);

        $this->info(sprintf(
            'Auditoría legacy (dry-run): %d hallazgo(s) en %d taquilla(s). Sin escrituras.',
            $total,
            count($grupos)
        ));

        if ($total === 0) {
            $this->info('No se encontraron casos C1-C5.');

            return self::SUCCESS;
        }

        foreach ($grupos as $taquillaId => $grupo) {
            $this->line(sprintf('Taquilla %d — %s', $taquillaId, $grupo['taquilla']));

            foreach (['C1/C2', 'C3', 'C4', 'C5'] as $clase) {
                $this->line(sprintf('  %s: %d', $clase, count($grupo[$clase])));

                foreach ($grupo[$clase] as $linea) {
                    $this->line('    - '.$linea);
                }
            }
        }

        $csvPath = $this->option('csv');

        if (is_string($csvPath) && $csvPath !== '') {
            if (! $this->escribirCsv($csvPath, $csv)) {
                return self::FAILURE;
            }

            $this->info('CSV escrito en '.$csvPath);
        }

        if ($total > 0) {
            $this->line('Para corregir: --apply --ids=<ids de apuesta revisados>. Para deshacer: --revert --ids=<ids>.');
        }

        return self::SUCCESS;
    }

    /**
     * C1 + C2: apuestas `pagada` sin ningún `pago` de tipo `egreso`.
     */
    private function consultaC1C2(): Collection
    {
        return DB::table('apuestas as a')
            ->leftJoin('detalle_apuestas as d', 'd.apuesta_id', '=', 'a.id')
            ->leftJoin('taquillas as t', 't.id', '=', 'a.taquilla_id')
            ->where('a.estado', 'pagada')
            ->whereNotExists(function ($sub) {
                $sub->selectRaw('1')
                    ->from('pagos as p')
                    ->whereColumn('p.apuesta_id', 'a.id')
                    ->where('p.tipo', 'egreso');
            })
            ->select([
                'a.id as apuesta_id',
                'a.ticket_id',
                'a.taquilla_id',
                't.name as taquilla',
                'a.estado',
                'a.resultado_id',
                'a.deleted_at',
                'd.premio_ganado',
                'd.premio_ganado_usd',
                DB::raw("(SELECT COUNT(*) FROM pagos p WHERE p.apuesta_id = a.id AND p.tipo = 'egreso') AS egresos"),
                DB::raw("(SELECT COUNT(*) FROM pagos p WHERE p.apuesta_id = a.id AND p.tipo = 'ingreso') AS ingresos"),
                DB::raw("(SELECT COUNT(*) FROM pagos p WHERE p.apuesta_id = a.id AND p.tipo = 'devolucion') AS devoluciones"),
            ])
            ->orderBy('a.taquilla_id')
            ->orderBy('a.id')
            ->get();
    }

    /**
     * C3 + C4: tickets `pagada` con una línea pagada sin egreso o sin líneas vivas.
     */
    private function consultaC3C4(): Collection
    {
        return DB::table('tickets as t')
            ->leftJoin('apuestas as a', function ($join) {
                $join->on('a.ticket_id', '=', 't.id')
                    ->whereNull('a.deleted_at');
            })
            ->leftJoin('taquillas as tq', 'tq.id', '=', 't.taquilla_id')
            ->where('t.estado', 'pagada')
            ->select([
                't.id as ticket_id',
                't.ticket_code',
                't.taquilla_id',
                'tq.name as taquilla',
                't.premio_total_bs',
                't.premio_total_usd',
            ])
            ->selectRaw(
                "COALESCE(SUM(CASE WHEN a.estado = 'pagada' AND NOT EXISTS (SELECT 1 FROM pagos p WHERE p.apuesta_id = a.id AND p.tipo = 'egreso') THEN 1 ELSE 0 END), 0) AS lineas_pagadas_sin_egreso"
            )
            ->selectRaw('COUNT(a.id) AS lineas_vivas')
            ->groupBy('t.id', 't.ticket_code', 't.taquilla_id', 'tq.name', 't.premio_total_bs', 't.premio_total_usd')
            ->havingRaw('lineas_pagadas_sin_egreso > 0 OR lineas_vivas = 0')
            ->orderBy('t.taquilla_id')
            ->orderBy('t.id')
            ->get();
    }

    /**
     * C5: ventas con más de un `ingreso`.
     */
    private function consultaC5(): Collection
    {
        return DB::table('pagos as p')
            ->join('apuestas as a', 'a.id', '=', 'p.apuesta_id')
            ->leftJoin('taquillas as t', 't.id', '=', 'a.taquilla_id')
            ->where('p.tipo', 'ingreso')
            ->whereNotNull('p.apuesta_id')
            ->select([
                'p.apuesta_id',
                'a.taquilla_id',
                't.name as taquilla',
            ])
            ->selectRaw('COUNT(*) AS ingresos')
            ->groupBy('p.apuesta_id', 'a.taquilla_id', 't.name')
            ->havingRaw('COUNT(*) > 1')
            ->orderBy('a.taquilla_id')
            ->orderBy('p.apuesta_id')
            ->get();
    }

    /**
     * @param  array<int, array<string, array<int, string>>>  $grupos
     */
    private function inicializarGrupo(array &$grupos, int $taquillaId, string $taquilla): void
    {
        if (! isset($grupos[$taquillaId])) {
            $grupos[$taquillaId] = [
                'taquilla' => $taquilla,
                'C1/C2' => [],
                'C3' => [],
                'C4' => [],
                'C5' => [],
            ];
        }
    }

    /**
     * @param  array<int, array<int, mixed>>  $rows
     */
    private function escribirCsv(string $path, array $rows): bool
    {
        $handle = @fopen($path, 'w');

        if ($handle === false) {
            $this->error('No se pudo escribir el CSV en '.$path);

            return false;
        }

        fputcsv($handle, ['clase', 'taquilla_id', 'taquilla', 'apuesta_id', 'ticket_id', 'detalle']);

        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }

        fclose($handle);

        return true;
    }

    // ------------------------------------------------------------------
    // Apply
    // ------------------------------------------------------------------

    /**
     * @param  array<int, int>  $ids
     */
    private function aplicar(array $ids): int
    {
        $this->info(sprintf('Aplicando corrección legacy a %d id(s) explícito(s)...', count($ids)));

        $aplicados = 0;
        $fallos = 0;

        foreach ($ids as $id) {
            try {
                $resultado = DB::transaction(fn (): array => $this->aplicarUno($id));
            } catch (Throwable $e) {
                $fallos++;
                $this->error(sprintf('apuesta=%d: error inesperado (%s); transacción revertida.', $id, $e->getMessage()));

                continue;
            }

            if ($resultado['ok']) {
                $aplicados++;
                $this->info('  - '.$resultado['message']);
            } else {
                $fallos++;
                $this->warn('  - '.$resultado['message']);
            }
        }

        $this->line(sprintf('Resumen: %d aplicada(s), %d no aplicada(s).', $aplicados, $fallos));

        if ($fallos > 0) {
            $this->error('Algunos ids requieren revisión manual o no son C1/C2; no se forzó ninguna escritura.');

            return self::FAILURE;
        }

        $this->info('Corrección aplicada. Para deshacer: --revert con los mismos ids.');

        return self::SUCCESS;
    }

    /**
     * @return array{ok: bool, message: string}
     */
    private function aplicarUno(int $id): array
    {
        $apuesta = Apuesta::with(['juego', 'resultado', 'detalles'])
            ->lockForUpdate()
            ->find($id);

        if (! $apuesta) {
            return $this->skip($id, 'no existe o está eliminada');
        }

        if ($apuesta->estado !== 'pagada') {
            return $this->skip($id, "no es C1/C2 (estado {$apuesta->estado})");
        }

        if (Pago::where('apuesta_id', $id)->where('tipo', 'egreso')->exists()) {
            return $this->skip($id, 'ya tiene egreso; no es C1/C2');
        }

        $detalle = $apuesta->detalles->first();

        if (! $detalle) {
            return [
                'ok' => false,
                'message' => "apuesta={$id}: sin detalle_apuestas; REVISION MANUAL (no se escribe)",
            ];
        }

        $ticket = $apuesta->ticket_id ? Ticket::withTrashed()->find($apuesta->ticket_id) : null;
        $lineasVivas = $ticket
            ? Apuesta::where('ticket_id', $ticket->id)->orderBy('id')->get()
            : collect();

        if ($ticket && $lineasVivas->count() > 1) {
            $problema = $this->reconciliarTicket($ticket, $lineasVivas);

            if ($problema !== null) {
                return [
                    'ok' => false,
                    'message' => "apuesta={$id} ticket={$ticket->id}: {$problema}; REVISION MANUAL (no se escribe)",
                ];
            }
        }

        $premio = $this->liquidador->calcular($apuesta, $apuesta->resultado);
        $premioBs = round((float) $premio['premio_bs'], 2);
        $premioUsd = round((float) $premio['premio_usd'], 2);
        $nuevoEstado = ($premioBs > 0 || $premioUsd > 0) ? 'ganadora' : 'perdida';

        $oldValues = [
            'estado' => $apuesta->estado,
            'premio_ganado' => $detalle->premio_ganado,
            'premio_ganado_usd' => $detalle->premio_ganado_usd,
            'ticket_estado' => $ticket?->estado,
        ];

        $apuesta->update(['estado' => $nuevoEstado]);
        $detalle->update([
            'premio_ganado' => $premioBs,
            'premio_ganado_usd' => $premioUsd,
        ]);

        $nuevoTicketEstado = $ticket?->estado;

        if ($ticket && ! $ticket->trashed()) {
            $nuevoTicketEstado = $this->calcularEstadoTicket($ticket);

            if ($nuevoTicketEstado !== $ticket->estado) {
                $ticket->update(['estado' => $nuevoTicketEstado]);
            }
        }

        $newValues = [
            'estado' => $nuevoEstado,
            'premio_ganado' => $premioBs,
            'premio_ganado_usd' => $premioUsd,
            'ticket_estado' => $nuevoTicketEstado,
        ];

        Log::create([
            'user_id' => null,
            'action' => self::ACTION,
            'details' => [
                'apuesta_id' => $apuesta->id,
                'ticket_id' => $apuesta->ticket_id,
                'premio_fuente' => 'motor',
                'command' => 'pagos:auditar-legacy',
                'old_values' => $oldValues,
                'new_values' => $newValues,
            ],
            'ip' => null,
            'user_agent' => null,
        ]);

        return [
            'ok' => true,
            'message' => sprintf(
                'apuesta=%d: estado %s -> %s, premio %s/%s, ticket %s -> %s',
                $apuesta->id,
                $oldValues['estado'],
                $nuevoEstado,
                $this->num($premioBs),
                $this->num($premioUsd),
                $oldValues['ticket_estado'] ?? 'null',
                $nuevoTicketEstado ?? 'null'
            ),
        ];
    }

    /**
     * Un ticket multilínea solo se escribe si la suma del motor cuadra con
     * `tickets.premio_total_*` (tolerancia ±0.01). Si no, revisión manual.
     *
     * @param  Collection<int, Apuesta>  $lineas
     */
    private function reconciliarTicket(Ticket $ticket, Collection $lineas): ?string
    {
        $totalBs = 0.0;
        $totalUsd = 0.0;

        foreach ($lineas as $linea) {
            $linea->loadMissing(['juego', 'resultado', 'detalles']);
            $premio = $this->liquidador->calcular($linea, $linea->resultado);
            $totalBs += (float) $premio['premio_bs'];
            $totalUsd += (float) $premio['premio_usd'];
        }

        $esperadoBs = (float) ($ticket->premio_total_bs ?? 0);
        $esperadoUsd = (float) ($ticket->premio_total_usd ?? 0);

        if (round(abs($totalBs - $esperadoBs), 2) > 0.01 || round(abs($totalUsd - $esperadoUsd), 2) > 0.01) {
            return sprintf(
                'multilínea no cuadra (motor %s/%s vs ticket %s/%s)',
                $this->num($totalBs),
                $this->num($totalUsd),
                $this->num($esperadoBs),
                $this->num($esperadoUsd)
            );
        }

        return null;
    }

    /**
     * Regla de estado del ticket (design §10.2): sin líneas vivas -> `anulada`;
     * alguna ganadora impaga -> `ganador`; todas resueltas -> `pagada`.
     */
    private function calcularEstadoTicket(Ticket $ticket): string
    {
        $lineas = Apuesta::where('ticket_id', $ticket->id)->get();

        if ($lineas->isEmpty()) {
            return 'anulada';
        }

        if ($lineas->contains(fn (Apuesta $linea): bool => $linea->estado === 'ganadora')) {
            return 'ganador';
        }

        $resueltas = ['pagada', 'anulada', 'perdida', 'vencido'];

        if ($lineas->every(fn (Apuesta $linea): bool => in_array($linea->estado, $resueltas, true))) {
            return 'pagada';
        }

        return $ticket->estado;
    }

    // ------------------------------------------------------------------
    // Revert
    // ------------------------------------------------------------------

    /**
     * @param  array<int, int>  $ids
     */
    private function revertir(array $ids): int
    {
        $this->info(sprintf('Revirtiendo %d id(s) desde el último log %s...', count($ids), self::ACTION));

        $ultimos = $this->ultimosLogsPorApuesta();

        $revertidos = 0;
        $fallos = 0;

        foreach ($ids as $id) {
            if (! isset($ultimos[$id])) {
                $fallos++;
                $this->warn("  - apuesta={$id}: sin log ".self::ACTION.'; no se revierte');

                continue;
            }

            try {
                $resultado = DB::transaction(fn (): array => $this->revertirUno($id, $ultimos[$id]));
            } catch (Throwable $e) {
                $fallos++;
                $this->error(sprintf('apuesta=%d: error inesperado (%s); transacción revertida.', $id, $e->getMessage()));

                continue;
            }

            if ($resultado['ok']) {
                $revertidos++;
                $this->info('  - '.$resultado['message']);
            } else {
                $fallos++;
                $this->warn('  - '.$resultado['message']);
            }
        }

        $this->line(sprintf('Resumen: %d revertida(s), %d no revertida(s).', $revertidos, $fallos));

        if ($fallos > 0) {
            $this->error('Algunos ids no se revirtieron; revisa la salida.');

            return self::FAILURE;
        }

        $this->info('Reversión completada (sin logs nuevos).');

        return self::SUCCESS;
    }

    /**
     * Último log `auditoria.pago_legacy` por `apuesta_id`.
     *
     * @return array<int, array<string, mixed>>
     */
    private function ultimosLogsPorApuesta(): array
    {
        $ultimos = [];

        $logs = Log::where('action', self::ACTION)->orderByDesc('id')->get();

        foreach ($logs as $log) {
            $details = $log->details ?? [];
            $apuestaId = (int) ($details['apuesta_id'] ?? 0);

            if ($apuestaId > 0 && ! isset($ultimos[$apuestaId])) {
                $ultimos[$apuestaId] = $details;
            }
        }

        return $ultimos;
    }

    /**
     * @param  array<string, mixed>  $details
     * @return array{ok: bool, message: string}
     */
    private function revertirUno(int $id, array $details): array
    {
        $apuesta = Apuesta::with('detalles')->lockForUpdate()->find($id);

        if (! $apuesta) {
            return [
                'ok' => false,
                'message' => "apuesta={$id}: no existe o está eliminada; no se revierte",
            ];
        }

        $old = $details['old_values'] ?? [];
        $new = $details['new_values'] ?? [];

        if (! array_key_exists('estado', $old)) {
            return [
                'ok' => false,
                'message' => "apuesta={$id}: log sin old_values.estado; no se revierte",
            ];
        }

        if (isset($new['estado']) && $apuesta->estado !== $new['estado']) {
            return [
                'ok' => false,
                'message' => "apuesta={$id}: estado actual {$apuesta->estado} distinto del aplicado {$new['estado']}; no se revierte",
            ];
        }

        $detalle = $apuesta->detalles->first();

        $apuesta->update(['estado' => $old['estado']]);

        if ($detalle) {
            $detalle->update([
                'premio_ganado' => $old['premio_ganado'] ?? null,
                'premio_ganado_usd' => $old['premio_ganado_usd'] ?? null,
            ]);
        }

        $ticketId = (int) ($details['ticket_id'] ?? 0);
        $oldTicketEstado = $old['ticket_estado'] ?? null;
        $newTicketEstado = $new['ticket_estado'] ?? null;

        if ($ticketId > 0 && $oldTicketEstado !== null) {
            $ticket = Ticket::withTrashed()->lockForUpdate()->find($ticketId);

            if ($ticket && ($newTicketEstado === null || $ticket->estado === $newTicketEstado)) {
                $ticket->update(['estado' => $oldTicketEstado]);
            }
        }

        return [
            'ok' => true,
            'message' => sprintf(
                'apuesta=%d: restaurada a estado %s, premio %s/%s, ticket %s',
                $id,
                $old['estado'],
                $this->num($old['premio_ganado'] ?? 0),
                $this->num($old['premio_ganado_usd'] ?? 0),
                $oldTicketEstado ?? 'n/a'
            ),
        ];
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * @return array<int, int>|null  null = formato inválido; [] = sin ids
     */
    private function parseIds(mixed $raw): ?array
    {
        if (! is_string($raw) || trim($raw) === '') {
            return [];
        }

        $ids = [];

        foreach (explode(',', $raw) as $token) {
            $token = trim($token);

            if ($token === '' || ! ctype_digit($token) || (int) $token <= 0) {
                return null;
            }

            $ids[] = (int) $token;
        }

        return array_values(array_unique($ids));
    }

    /**
     * @return array{ok: bool, message: string}
     */
    private function skip(int $id, string $motivo): array
    {
        return [
            'ok' => false,
            'message' => "apuesta={$id}: {$motivo} (no se escribe)",
        ];
    }

    private function num(mixed $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}
