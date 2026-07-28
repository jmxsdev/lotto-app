<?php

namespace Database\Seeders;

use App\Models\Apuesta;
use App\Models\DetalleApuesta;
use App\Models\ExchangeRate;
use App\Models\Juego;
use App\Models\Resultado;
use App\Models\Taquilla;
use App\Models\Pago;
use App\Services\JuegoPluginManager;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class ApuestaGanadoraSeeder extends Seeder
{
    protected array $apuestasData = [
        [
            'juego_slug' => 'animalitos',
            'combinacion' => ['animal' => 'perro', 'numero' => 27],
            'amount_bs' => 10,
            'amount_usd' => 10,
            'sorteo_hora' => '2026-07-24 19:00:00',
            'resultado_nombre_animal' => 'perro',
        ],
        [
            'juego_slug' => 'triple-zulia',
            'combinacion' => ['tipo' => 'triple_c', 'numero' => '789', 'signo' => 'LEO'],
            'amount_bs' => 20,
            'amount_usd' => 0,
            'sorteo_hora' => '2026-07-24 19:05:00',
            'resultado_numeros' => ['triple_a' => '123', 'triple_b' => '456', 'triple_c' => '789', 'signo' => 'LEO'],
        ],
        [
            'juego_slug' => 'animalitos',
            'combinacion' => ['animal' => 'caballo', 'numero' => 12],
            'amount_bs' => 5,
            'amount_usd' => 0,
            'sorteo_hora' => '2026-07-24 19:00:00',
            'resultado_nombre_animal' => 'perro',
        ],
    ];

    public function run(): void
    {
        $tasa = ExchangeRate::where('is_active', true)->first();
        if (!$tasa) {
            $this->command->error('No hay tasa activa. Ejecuta ExchangeRateSeeder primero.');
            return;
        }

        $taquilla = Taquilla::first();
        if (!$taquilla) {
            $this->command->error('No hay taquillas. Crea una taquilla primero.');
            return;
        }

        $user = \App\Models\User::where('email', 'super@lotto.com')->first();
        if (!$user) {
            $this->command->error('No hay usuario super_master. Ejecuta UsersSeeder primero.');
            return;
        }

        foreach ($this->apuestasData as $data) {
            $juego = Juego::where('slug', $data['juego_slug'])->first();
            if (!$juego) {
                $this->command->warn("Juego {$data['juego_slug']} no encontrado. Omitiendo.");
                continue;
            }

            $amountBs = $data['amount_bs'];
            $amountUsd = $data['amount_usd'];
            $totalBsEquivalent = $amountBs + ($amountUsd * $tasa->rate);

            $apuesta = Apuesta::create([
                'taquilla_id' => $taquilla->id,
                'juego_id' => $juego->id,
                'resultado_id' => null,
                'combinacion' => json_encode($data['combinacion']),
                'amount_bs' => $amountBs,
                'amount_usd' => $amountUsd,
                'exchange_rate_applied' => $tasa->rate,
                'total_bs_equivalent' => $totalBsEquivalent,
                'estado' => 'pendiente',
                'fecha_hora' => Carbon::yesterday()->subHour(),
                'sorteo_hora' => $data['sorteo_hora'],
            ]);

            // Calcular premio posible
            $plugin = app(JuegoPluginManager::class)->getPlugin($juego);
            $premio = $plugin
                ? $plugin->calcularPremio(
                    ['combinacion' => $data['combinacion'], 'amount_bs' => $amountBs, 'amount_usd' => $amountUsd],
                    ['nombre_animal' => $data['resultado_nombre_animal'] ?? null, 'numeros_ganadores' => $data['resultado_numeros'] ?? []]
                )
                : ['premio_bs' => $totalBsEquivalent, 'premio_usd' => 0];

            DetalleApuesta::create([
                'apuesta_id' => $apuesta->id,
                'combinacion' => json_encode($data['combinacion']),
                'monto' => $totalBsEquivalent,
                'premio_posible' => $premio['premio_bs'],
                'premio_posible_usd' => $premio['premio_usd'],
                'premio_ganado' => null,
                'premio_ganado_usd' => null,
            ]);

            $moneda = $amountBs > 0 && $amountUsd > 0 ? 'mixto' : ($amountUsd > 0 ? 'usd' : 'bs');

            Pago::create([
                'taquilla_id' => $taquilla->id,
                'apuesta_id' => $apuesta->id,
                'amount_bs' => $amountBs,
                'amount_usd' => $amountUsd,
                'exchange_rate_applied' => $tasa->rate,
                'tipo' => 'ingreso',
                'moneda' => $moneda,
                'concepto' => 'Compra de ticket',
                'created_by' => $user->id,
            ]);

            // Buscar el resultado del sorteo y asignarlo a la apuesta
            $resultado = Resultado::where('juego_id', $juego->id)
                ->where('hora_sorteo', date('H:i', strtotime($data['sorteo_hora'])))
                ->first();

            if ($resultado) {
                $apuesta->update(['resultado_id' => $resultado->id]);
                $this->command->info("Apuesta {$apuesta->id} ({$data['juego_slug']}) creada con resultado asignado.");
                $this->command->info("  Ingreso: {$amountBs} BS + {$amountUsd} USD | Premio: {$premio['premio_bs']} BS + {$premio['premio_usd']} USD");
            } else {
                $this->command->warn("Apuesta {$apuesta->id} creada SIN resultado (ejecuta ResultadoTestSeeder primero).");
            }
        }
    }
}
