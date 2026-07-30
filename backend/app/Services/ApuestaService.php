<?php

namespace App\Services;

use App\Models\ExchangeRate;
use App\Models\Juego;
use App\Models\JuegoHorario;

class ApuestaService
{
    /**
     * Calcular total_bs_equivalent usando tasa activa del momento
     */
    public function calcularTotal(float $amountBs, float $amountUsd): float
    {
        $tasaActiva = ExchangeRate::where('is_active', true)->first();
        
        if (!$tasaActiva) {
            throw new \RuntimeException('No hay tasa activa configurada');
        }
        
        return $amountBs + ($amountUsd * $tasaActiva->rate);
    }

    /**
     * Obtener la tasa activa actual (null si no existe)
     */
    public function getTasaActiva(): ?float
    {
        $tasa = ExchangeRate::where('is_active', true)->first();
        return $tasa ? $tasa->rate : null;
    }

    /**
     * Validar que el monto cubre el costo mínimo del juego
     */
    public function validarCostoMinimo(float $totalBsEquivalent, int $juegoId): array
    {
        $juego = Juego::find($juegoId);

        if (!$juego) {
            return [
                'valid' => false,
                'message' => 'Juego no encontrado.',
            ];
        }

        $costoMinimo = $juego->costo_minimo;

        if ($costoMinimo === null) {
            return [
                'valid' => true,
                'costo_minimo' => null,
            ];
        }

        if ($totalBsEquivalent < $costoMinimo) {
            return [
                'valid' => false,
                'message' => "El monto no cubre el costo mínimo del juego.",
                'required_min' => (float) $costoMinimo,
                'current_total' => $totalBsEquivalent,
            ];
        }

        return [
            'valid' => true,
            'costo_minimo' => (float) $costoMinimo,
        ];
    }

    /**
     * Convertir BS a USD equivalente (para mostrar al usuario)
     */
    public function bsToUsd(float $amountBs): float
    {
        $tasaActiva = ExchangeRate::where('is_active', true)->first();
        return $tasaActiva ? $amountBs / $tasaActiva->rate : 0;
    }

    /**
     * Convertir USD a BS equivalente (para cálculo)
     */
    public function usdToBs(float $amountUsd): float
    {
        $tasaActiva = ExchangeRate::where('is_active', true)->first();
        return $tasaActiva ? $amountUsd * $tasaActiva->rate : 0;
    }

    /**
     * Obtener el siguiente sorteo según horarios del juego
     */
    public function getNextDrawTime(int $juegoId): string
    {
        $horarios = JuegoHorario::where('juego_id', $juegoId)
            ->where('active', true)
            ->orderBy('hora')
            ->pluck('hora');

        if ($horarios->isEmpty()) {
            return now()->addHours(2)->format('Y-m-d H:i:s');
        }

        $now = now();
        foreach ($horarios as $hora) {
            $drawTime = $now->copy()->setTimeFromTimeString($hora);
            if ($drawTime->isFuture()) {
                return $drawTime->format('Y-m-d H:i:s');
            }
        }

        return $now->copy()->addDay()->setTimeFromTimeString($horarios->first())->format('Y-m-d H:i:s');
    }

    /**
     * Obtener resumen estadístico de apuestas
     */
    public function obtenerResumen($query): array
    {
        $totalBs = $query->sum('amount_bs') ?: 0;
        $totalUsd = $query->sum('amount_usd') ?: 0;
        $totalBetAmount = $query->sum('total_bs_equivalent') ?: 0;
        
        $pendingCount = (clone $query)->where('estado', 'pendiente')->count();
        $pagadaCount = (clone $query)->where('estado', 'pagada')->count();
        $anuladaCount = (clone $query)->where('estado', 'anulada')->count();
        
        return [
            'total_bs' => round($totalBs, 2),
            'total_usd' => round($totalUsd, 2),
            'total_bet_amount_bs' => round($totalBetAmount, 2),
            'pending_count' => $pendingCount,
            'pagada_count' => $pagadaCount,
            'anulada_count' => $anuladaCount,
        ];
    }

    /**
     * Crear una apuesta individual (reutilizable desde ApuestaController y TicketController)
     */
    public function createApuesta(array $data, int $taquillaId, int $userId, ?int $ticketId = null): \App\Models\Apuesta
    {
        $tasaActiva = ExchangeRate::where('is_active', true)->first();

        if (!$tasaActiva) {
            throw new \RuntimeException('No hay tasa de cambio activa configurada. Contacte al administrador.');
        }

        $amountBs = (float) ($data['amount_bs'] ?? 0);
        $amountUsd = (float) ($data['amount_usd'] ?? 0);
        $totalBsEquivalent = $amountBs + ($amountUsd * $tasaActiva->rate);

        $validacion = $this->validarCostoMinimo($totalBsEquivalent, $data['juego_id']);

        if (!$validacion['valid']) {
            throw new \RuntimeException($validacion['message'] . ' (Monto actual: ' . round($totalBsEquivalent, 2) . ' Bs)');
        }

        $combinacion = $data['combinacion'] ?? [];

        $apuestaData = [
            'taquilla_id' => $taquillaId,
            'juego_id' => $data['juego_id'],
            'combinacion' => json_encode($combinacion),
            'amount_bs' => $amountBs,
            'amount_usd' => $amountUsd,
            'exchange_rate_applied' => $tasaActiva->rate,
            'total_bs_equivalent' => $totalBsEquivalent,
            'estado' => 'pendiente',
            'fecha_hora' => now(),
            'sorteo_hora' => $data['sorteo_hora'] ?? $this->getNextDrawTime($data['juego_id']),
        ];

        if ($ticketId) {
            $apuestaData['ticket_id'] = $ticketId;
        }

        $apuesta = \App\Models\Apuesta::create($apuestaData);

        // Generar detalles con plugin
        $juego = \App\Models\Juego::find($data['juego_id']);
        if ($juego) {
            $plugin = app(\App\Services\JuegoPluginManager::class)->getPlugin($juego);
            $premio = $plugin
                ? $plugin->calcularPremio(
                    ['combinacion' => $combinacion, 'total_bs_equivalent' => $totalBsEquivalent, 'amount_bs' => $amountBs, 'amount_usd' => $amountUsd],
                    []
                )
                : ['premio_bs' => $totalBsEquivalent, 'premio_usd' => 0];

            \App\Models\DetalleApuesta::create([
                'apuesta_id' => $apuesta->id,
                'combinacion' => json_encode($combinacion),
                'monto' => $totalBsEquivalent,
                'premio_posible' => $premio['premio_bs'],
                'premio_posible_usd' => $premio['premio_usd'],
                'premio_ganado' => null,
                'premio_ganado_usd' => null,
            ]);
        }

        // Crear pago
        $moneda = $amountBs > 0 && $amountUsd > 0 ? 'mixto' : ($amountUsd > 0 ? 'usd' : 'bs');

        \App\Models\Pago::create([
            'taquilla_id' => $taquillaId,
            'apuesta_id' => $apuesta->id,
            'amount_bs' => $amountBs,
            'amount_usd' => $amountUsd,
            'exchange_rate_applied' => $tasaActiva->rate,
            'tipo' => 'ingreso',
            'moneda' => $moneda,
            'concepto' => 'Compra de ticket',
            'created_by' => $userId,
        ]);

        return $apuesta;
    }
}
