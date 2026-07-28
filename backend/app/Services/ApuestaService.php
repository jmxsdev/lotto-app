<?php

namespace App\Services;

use App\Models\ExchangeRate;
use App\Models\Juego;

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
}
