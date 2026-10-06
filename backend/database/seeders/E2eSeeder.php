<?php

namespace Database\Seeders;

use App\Models\Apuesta;
use App\Models\CierreCaja;
use App\Models\DetalleApuesta;
use App\Models\ExchangeRate;
use App\Models\Grupo;
use App\Models\Juego;
use App\Models\Pago;
use App\Models\Resultado;
use App\Models\Taquilla;
use App\Models\Ticket;
use App\Models\User;
use App\Services\JuegoPluginManager;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Seeder opt-in e idempotente de datos de prueba E2E (REQ-6).
 *
 * VIVE FUERA de DatabaseSeeder a propósito (guard line a): el harness lo
 * invoca explícitamente (`pnpm e2e:seed` / CI) sobre una base ya sembrada con
 * los seeders normales. El contrato de idempotencia es re-ejecutable:
 * `updateOrCreate` para la taquilla/usuario/resultado/ticket y un reset
 * explícito del fixture de premio (borra los pagos del fixture y restaura
 * estado pendiente) para que el spec 04-premio pueda pagar el premio una y
 * otra vez entre corridas.
 *
 * Datos que crea/actualiza:
 *  - Taquilla E2E01 activa (MAC/fingerprint desde env, tiempo_eliminacion=1440
 *    para que la ventana F10 de anulación quede abierta bajo el reloj del
 *    harness; AD-8 del design).
 *  - Usuario e2e@lotto.com (role taquilla, active, clave_cierre).
 *  - clave_cierre de cierre en los candidatos que valida CierreService
 *    (banca del grupo + super_master): el re-cierre exige Hash::check contra
 *    ellos, nunca contra el usuario taquilla.
 *  - Resultado de animalitos (juego lotto-activo) de ayer 19:00 America/Caracas
 *    con ganador perro #27.
 *  - Ticket ganador E2E-WIN-0001 (apuesta perro 27, monto 100 Bs, sorteo de
 *    ayer 19:00) + detalle con premio_posible del motor + pago ingreso.
 *    MONTO DELIBERADAMENTE BAJO (100 Bs → premio 3.000 Bs): el spec 07-cierre
 *    necesita efectivo esperado >= 0 para el badge CONCILIADO (arqueo tiene
 *    `min:0`); el egreso del premio (spec 04) = monto × 30 debe quedar por
 *    debajo de las ventas acumuladas del suite (specs 02/03).
 *
 * NOTA: se usa el MAC corregido `02:E2:E0:00:00:01` (el literal original del
 * design `02:E2E:00:00:00:01` es inválido para VerifyMac.php — 3 hex seguidos
 * → 403; ver discovery de S1 en apply-progress).
 */
class E2eSeeder extends Seeder
{
    public function run(): void
    {
        $this->command->info('E2eSeeder: preparando datos de prueba E2E...');

        $mac = strtoupper(trim((string) env('E2E_MAC', '02:E2:E0:00:00:01')));
        $fingerprint = (string) env('E2E_FINGERPRINT', 'e2e-device-0001');
        $clave = (string) env('E2E_CIERRE_CLAVE', '123456');

        // Cadena jerárquica de los seeders normales (grupo Test → banca Test):
        // el E2E se apoya en ella para alcances y para la clave de cierre.
        $grupo = Grupo::first();
        if (! $grupo) {
            $this->command->error('E2eSeeder: no hay grupos. Ejecuta DatabaseSeeder primero.');

            return;
        }

        $tasa = ExchangeRate::where('is_active', true)->first();
        $juego = Juego::where('slug', 'lotto-activo')->first();
        if (! $juego || ! $tasa) {
            $this->command->error('E2eSeeder: faltan el juego lotto-activo o la tasa activa. Ejecuta DatabaseSeeder primero.');

            return;
        }

        $super = User::where('role', 'super_master')->first();
        $creadoPor = $super?->id;

        // 0. Liberar la identidad E2E: DEMO01 (provisional de S1) pudo haber
        // tomado el MAC/fingerprint E2E a mano. Se restaura a sus valores de
        // UsersSeeder para que `device_fingerprint` (único) quede para E2E01.
        $demo = Taquilla::where('code', 'DEMO01')->first();
        if ($demo) {
            $demo->update([
                'mac_address' => '00:1A:2B:3C:4D:5E',
                'device_fingerprint' => 'demo-device-001',
            ]);
        }

        // 1. Taquilla E2E activa (idempotente). tiempo_eliminacion=1440 (24 h)
        // mantiene abierta la ventana de anulación F10 para el spec 05.
        $taquilla = Taquilla::updateOrCreate(
            ['code' => 'E2E01'],
            [
                'name' => 'Taquilla E2E',
                'grupo_id' => $grupo->id,
                'mac_address' => $mac,
                'device_fingerprint' => $fingerprint,
                'activation_code' => 'E2E01',
                'tiempo_eliminacion' => 1440,
                'vigencia_premios' => 30,
                'active' => true,
                'created_by' => $creadoPor,
            ]
        );

        // 2. Usuario E2E (role taquilla) ligado a E2E01.
        $user = User::updateOrCreate(
            ['email' => 'e2e@lotto.com'],
            [
                'name' => 'E2E User',
                'password' => Hash::make((string) env('SEEDER_PASSWORD', 'password')),
                'role' => 'taquilla',
                'banca_id' => $grupo->banca_id,
                'grupo_id' => $grupo->id,
                'taquilla_id' => $taquilla->id,
                'active' => true,
                'clave_cierre' => Hash::make($clave),
            ]
        );
        $user->assignRole('taquilla');

        // 3. clave_cierre en los candidatos que valida CierreService::validarClaveCierre
        // (banca del grupo + super_master). Determinista para el harness: la clave
        // E2E siempre funciona en re-cierre (spec 07).
        $candidatos = User::where('role', 'super_master')
            ->orWhere(fn ($q) => $q->where('role', 'banca')->where('banca_id', $grupo->banca_id))
            ->get();
        foreach ($candidatos as $candidato) {
            $candidato->update(['clave_cierre' => Hash::make($clave)]);
        }

        // 4. Resultado animalitos de AYER 19:00 America/Caracas (la app corre
        // en la zona Caracas; el spec 04 busca ganadores con esa fecha).
        $fechaSorteo = Carbon::now('America/Caracas')->subDay();
        $fechaStr = $fechaSorteo->toDateString();

        $resultado = Resultado::updateOrCreate(
            [
                'juego_id' => $juego->id,
                'fecha_sorteo' => $fechaStr,
                'hora_sorteo' => '19:00',
            ],
            [
                'numeros_ganadores' => ['nombre_animal' => 'perro', 'numero' => 27],
                'sorteo_id_externo' => 'E2E-'.$fechaStr,
            ]
        );

        // 5. Fixture de premio E2E-WIN-0001 con RESET idempotente: borra los
        // pagos del fixture (ingreso del seeder y egreso del spec 04) y
        // restaura ticket/apuesta/detalle a estado pendiente para que el
        // spec 04 pueda pagarlo en cada corrida. También borra los cierres
        // E2E previos: el spec 07 crea cierres y un cierre viejo truncaría el
        // período del preview (efectivo esperado distorsionado).
        CierreCaja::where('taquilla_id', $taquilla->id)->delete();

        $ticket = Ticket::withTrashed()->firstOrNew(['ticket_code' => 'E2E-WIN-0001']);
        $ticket->fill([
            'taquilla_id' => $taquilla->id,
            'total_bs' => 100,
            'total_usd' => 0,
            'premio_total_bs' => null,
            'premio_total_usd' => null,
            'estado' => 'pendiente',
        ]);
        $ticket->deleted_at = null; // restaurar si una corrida previa lo anuló
        $ticket->save();

        $apuesta = Apuesta::withTrashed()->firstOrNew([
            'ticket_id' => $ticket->id,
            'juego_id' => $juego->id,
        ]);
        $apuesta->fill([
            'taquilla_id' => $taquilla->id,
            'resultado_id' => $resultado->id,
            'combinacion' => json_encode(['animal' => 'perro', 'numero' => 27]),
            'ticket_code' => 'E2E-WIN-0001',
            'amount_bs' => 100,
            'amount_usd' => 0,
            'exchange_rate_applied' => $tasa->rate,
            'total_bs_equivalent' => 100,
            'estado' => 'pendiente',
            'fecha_hora' => $fechaSorteo->copy()->setTime(18, 50),
            'sorteo_hora' => $fechaSorteo->copy()->setTime(19, 0)->format('Y-m-d H:i:s'),
        ]);
        $apuesta->deleted_at = null;
        $apuesta->save();

        // Reset: los pagos del fixture (ingreso previo y egreso del spec 04)
        // se borran SIEMPRE antes de recrear el ingreso (idempotencia + premio
        // re-pagable). `calcularTotales` del cierre vuelve a su línea base.
        Pago::where('apuesta_id', $apuesta->id)->delete();

        $premio = app(JuegoPluginManager::class)->calcularPremio(
            $juego,
            ['combinacion' => ['animal' => 'perro', 'numero' => 27], 'amount_bs' => 100, 'amount_usd' => 0],
            ['numeros_ganadores' => $resultado->numeros_ganadores]
        );

        $detalle = DetalleApuesta::firstOrNew(['apuesta_id' => $apuesta->id]);
        $detalle->fill([
            'combinacion' => json_encode(['animal' => 'perro', 'numero' => 27]),
            'monto' => 100,
            'premio_posible' => $premio['premio_bs'] ?? 0,
            'premio_posible_usd' => $premio['premio_usd'] ?? 0,
            'premio_ganado' => null,
            'premio_ganado_usd' => null,
            'premios_snapshot' => $juego->config['premios'] ?? null,
        ]);
        $detalle->save();

        Pago::create([
            'taquilla_id' => $taquilla->id,
            'apuesta_id' => $apuesta->id,
            'amount_bs' => 100,
            'amount_usd' => 0,
            'exchange_rate_applied' => $tasa->rate,
            'tipo' => 'ingreso',
            'moneda' => 'bs',
            'concepto' => 'Compra de ticket (E2E)',
            'created_by' => $user->id,
        ]);

        $this->command->info(sprintf(
            'E2eSeeder: taquilla E2E01 (mac %s, fp %s) + usuario e2e@lotto.com + resultado %s 19:00 + ticket E2E-WIN-0001 (premio %s Bs) listos.',
            $mac,
            $fingerprint,
            $fechaStr,
            number_format($premio['premio_bs'] ?? 0, 2)
        ));
    }
}
