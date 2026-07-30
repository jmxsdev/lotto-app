<?php

namespace Database\Seeders;

use App\Models\Apuesta;
use App\Models\DetalleApuesta;
use App\Models\ExchangeRate;
use App\Models\Juego;
use App\Models\Pago;
use App\Models\Resultado;
use App\Models\Taquilla;
use App\Models\Ticket;
use App\Services\ApuestaService;
use App\Services\JuegoPluginManager;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class TicketsGanadoresDemoSeeder extends Seeder
{
    public function run(): void
    {
        $taquilla = Taquilla::where('id', 2)->first();
        if (!$taquilla) return;

        $tasaActiva = ExchangeRate::where('is_active', true)->first();
        if (!$tasaActiva) return;

        $pluginManager = app(JuegoPluginManager::class);

        $resultados = Resultado::with('juego')->whereNotNull('hora_sorteo')->limit(10)->get();
        if ($resultados->isEmpty()) return;

        foreach ($resultados as $resultado) {
            $plugin = $pluginManager->getPlugin($resultado->juego);
            if (!$plugin) continue;

            $ganador = $resultado->numeros_ganadores ?? [];
            $nombreAnimal = $ganador['nombre_animal'] ?? null;
            $numeroGanador = $ganador['numero'] ?? null;
            $tripleA = $ganador['triple_a'] ?? null;
            $tripleB = $ganador['triple_b'] ?? null;
            $tripleC = $ganador['triple_c'] ?? null;
            $signo = $ganador['signo'] ?? null;

            $fecha = $resultado->fecha_sorteo->toDateString();
            $hora = $resultado->hora_sorteo;
            if (preg_match('/AM|PM/i', $hora)) {
                $hora = \Carbon\Carbon::createFromFormat('h:i A', trim($hora))->format('H:i');
            }
            $sorteoHora = $fecha . ' ' . $hora . ':00';

            $ticket = Ticket::create([
                'taquilla_id' => $taquilla->id,
                'total_bs' => 0,
                'total_usd' => 0,
                'estado' => 'ganador',
            ]);

            $totalBs = 0;
            $totalUsd = 0;
            $premioTotalBs = 0;
            $premioTotalUsd = 0;

            // Jugada ganadora
            $combinacionGanadora = [];
            if ($nombreAnimal && $numeroGanador !== null) {
                $combinacionGanadora = ['animal' => $nombreAnimal, 'numero' => (string) $numeroGanador];
            } elseif ($tripleA) {
                $combinacionGanadora = ['tipo' => 'triple_a', 'numero' => $tripleA];
            } elseif ($tripleB) {
                $combinacionGanadora = ['tipo' => 'triple_b', 'numero' => $tripleB];
            } elseif ($tripleC) {
                $combinacionGanadora = ['tipo' => 'triple_c', 'numero' => $tripleC, 'signo' => $signo];
            }

            $amountBs = rand(4000, 10000);
            $totalBsEquivalent = $amountBs;
            $premioResultado = $plugin->calcularPremio(
                ['combinacion' => $combinacionGanadora, 'amount_bs' => $amountBs, 'amount_usd' => 0],
                ['numeros_ganadores' => $resultado->numeros_ganadores]
            );
            $premioBs = $premioResultado['premio_bs'] ?? 0;

            $apuestaGanadora = Apuesta::create([
                'taquilla_id' => $taquilla->id,
                'ticket_id' => $ticket->id,
                'juego_id' => $resultado->juego_id,
                'resultado_id' => $resultado->id,
                'combinacion' => json_encode($combinacionGanadora),
                'amount_bs' => $amountBs,
                'amount_usd' => 0,
                'exchange_rate_applied' => $tasaActiva->rate,
                'total_bs_equivalent' => $totalBsEquivalent,
                'estado' => 'pendiente',
                'fecha_hora' => now()->subMinutes(10),
                'sorteo_hora' => $sorteoHora,
            ]);

            DetalleApuesta::create([
                'apuesta_id' => $apuestaGanadora->id,
                'combinacion' => json_encode($combinacionGanadora),
                'monto' => $totalBsEquivalent,
                'premio_posible' => $premioBs,
                'premio_posible_usd' => 0,
                'premio_ganado' => $premioBs,
                'premio_ganado_usd' => null,
            ]);

            $totalBs += $amountBs;
            $premioTotalBs += $premioBs;

            // 2-3 jugadas perdedoras
            $perdedoras = rand(2, 3);
            $opciones = $plugin->obtenerOpciones();
            for ($i = 0; $i < $perdedoras; $i++) {
                $perdedora = $opciones[array_rand($opciones)];
                while (
                    ($nombreAnimal && isset($perdedora['value']) && strtolower($perdedora['value']) === strtolower($nombreAnimal))
                    || ($tripleA && isset($perdedora['value']) && $perdedora['value'] === $tripleA)
                ) {
                    $perdedora = $opciones[array_rand($opciones)];
                }

                $comboPerdedora = [];
                if ($nombreAnimal) {
                    $comboPerdedora = ['animal' => $perdedora['label'] ?? ($perdedora['value'] ?? '?'), 'numero' => (string) ($perdedora['numero'] ?? '0')];
                } elseif ($tripleA || $tripleB) {
                    $comboPerdedora = ['tipo' => $tripleA ? 'triple_a' : 'triple_b', 'numero' => str_pad(rand(0, 999), 3, '0', STR_PAD_LEFT)];
                } else {
                    $comboPerdedora = ['tipo' => 'triple_c', 'numero' => str_pad(rand(0, 999), 3, '0', STR_PAD_LEFT), 'signo' => $perdedora['label'] ?? ($perdedora['value'] ?? '?')];
                }

                $amount = rand(4000, 10000);
                $totalEq = $amount;

                $apuestaPerdida = Apuesta::create([
                    'taquilla_id' => $taquilla->id,
                    'ticket_id' => $ticket->id,
                    'juego_id' => $resultado->juego_id,
                    'resultado_id' => $resultado->id,
                    'combinacion' => json_encode($comboPerdedora),
                    'amount_bs' => $amount,
                    'amount_usd' => 0,
                    'exchange_rate_applied' => $tasaActiva->rate,
                    'total_bs_equivalent' => $totalEq,
                    'estado' => 'perdida',
                    'fecha_hora' => now()->subMinutes(10),
                    'sorteo_hora' => $sorteoHora,
                ]);

                DetalleApuesta::create([
                    'apuesta_id' => $apuestaPerdida->id,
                    'combinacion' => json_encode($comboPerdedora),
                    'monto' => $totalEq,
                    'premio_posible' => $totalEq * (float) $plugin->obtenerMultiplicador(),
                    'premio_posible_usd' => 0,
                    'premio_ganado' => null,
                    'premio_ganado_usd' => null,
                ]);

                $totalBs += $amount;
            }

            $ticket->update([
                'total_bs' => round($totalBs, 2),
                'total_usd' => 0,
                'premio_total_bs' => round($premioTotalBs, 2),
                'premio_total_usd' => 0,
            ]);
        }
    }
}
