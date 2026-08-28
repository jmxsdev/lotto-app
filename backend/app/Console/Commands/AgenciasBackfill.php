<?php

namespace App\Console\Commands;

use App\Models\Agencia;
use App\Models\Banca;
use App\Models\Grupo;
use App\Models\Taquilla;
use App\Models\User;
use Illuminate\Console\Command;

class AgenciasBackfill extends Command
{
    protected $signature = 'agencias:backfill
        {--force : Ejecutar sin confirmación en producción}
        {--dry-run : Mostrar qué haría sin escribir en la base de datos}';

    protected $description = 'Crea 1 local (agencia) por grupo y vincula taquillas/usuarios y masters (idempotente)';

    public function handle(): int
    {
        if (app()->environment('production') && ! $this->option('force')) {
            $this->error('Entorno de producción: ejecuta con --force para confirmar el backfill.');

            return self::FAILURE;
        }

        $dryRun = $this->option('dry-run');
        $counts = [
            'agencias_creadas' => 0,
            'taquillas_asignadas' => 0,
            'taquillas_skipped' => 0,
            'usuarios_asignados' => 0,
            'usuarios_skipped' => 0,
            'bancas_master_asignadas' => 0,
            'bancas_master_skipped' => 0,
        ];

        $super = User::query()->where('role', 'super_master')->orderBy('id')->first();

        // 1. Crear 1 local por grupo sin agencias
        $gruposSinAgencia = Grupo::query()
            ->whereDoesntHave('agencias')
            ->get();

        foreach ($gruposSinAgencia as $grupo) {
            $code = $this->generarCodeUnico($grupo);

            if ($dryRun) {
                $this->line("[dry-run] Crearía agencia '{$grupo->name} - Local' ({$code}) para el grupo {$grupo->id}");
                $counts['agencias_creadas']++;

                continue;
            }

            Agencia::create([
                'name' => $grupo->name.' - Local',
                'code' => $code,
                'grupo_id' => $grupo->id,
                'active' => true,
                'created_by' => $super?->id,
            ]);
            $counts['agencias_creadas']++;
            $this->line("Creada agencia '{$grupo->name} - Local' ({$code}) para el grupo {$grupo->id}");
        }

        // 2. Asignar agencia_id a taquillas y usuarios (rol taquilla) por grupo
        foreach (Grupo::query()->with('agencias')->get() as $grupo) {
            $agencia = $grupo->agencias()->first();

            if (! $agencia) {
                continue;
            }

            foreach ($grupo->taquillas()->get() as $taquilla) {
                if ($taquilla->agencia_id !== null) {
                    $counts['taquillas_skipped']++;

                    continue;
                }

                if ($dryRun) {
                    $this->line("[dry-run] Asignaría agencia {$agencia->id} a la taquilla {$taquilla->id}");
                    $counts['taquillas_asignadas']++;

                    continue;
                }

                $taquilla->update(['agencia_id' => $agencia->id]);
                $counts['taquillas_asignadas']++;
                $this->line("Asignada agencia {$agencia->id} a la taquilla {$taquilla->id}");
            }

            $usuarios = User::query()
                ->where('role', 'taquilla')
                ->whereHas('taquilla', fn ($q) => $q->where('grupo_id', $grupo->id))
                ->get();

            foreach ($usuarios as $usuario) {
                if ($usuario->agencia_id !== null) {
                    $counts['usuarios_skipped']++;

                    continue;
                }

                if ($dryRun) {
                    $this->line("[dry-run] Asignaría agencia {$agencia->id} al usuario {$usuario->id}");
                    $counts['usuarios_asignados']++;

                    continue;
                }

                $usuario->update(['agencia_id' => $agencia->id]);
                $counts['usuarios_asignados']++;
                $this->line("Asignada agencia {$agencia->id} al usuario {$usuario->id}");
            }
        }

        // 3. bancas.master_id desde created_by (solo si el creador es rol master)
        $bancasSinMaster = Banca::query()
            ->whereNull('master_id')
            ->whereNotNull('created_by')
            ->get();

        foreach ($bancasSinMaster as $banca) {
            $creador = User::find($banca->created_by);

            if (! $creador || $creador->role !== 'master') {
                $counts['bancas_master_skipped']++;

                continue;
            }

            if ($dryRun) {
                $this->line("[dry-run] Asignaría master {$creador->id} a la banca {$banca->id}");
                $counts['bancas_master_asignadas']++;

                continue;
            }

            $banca->update(['master_id' => $creador->id]);
            $counts['bancas_master_asignadas']++;
            $this->line("Asignado master {$creador->id} a la banca {$banca->id}");
        }

        $this->info(sprintf(
            'Backfill completado: %d agencias creadas, %d taquillas asignadas (%d ya tenían), %d usuarios asignados (%d ya tenían), %d bancas con master (%d sin creador master).',
            $counts['agencias_creadas'],
            $counts['taquillas_asignadas'],
            $counts['taquillas_skipped'],
            $counts['usuarios_asignados'],
            $counts['usuarios_skipped'],
            $counts['bancas_master_asignadas'],
            $counts['bancas_master_skipped'],
        ));

        return self::SUCCESS;
    }

    /**
     * Genera un code único para la agencia: "{grupo.code}-L01", con sufijo
     * numérico creciente si el code ya existe.
     */
    private function generarCodeUnico(Grupo $grupo): string
    {
        $base = strtoupper($grupo->code).'-L';
        $suffix = 1;

        while (Agencia::withTrashed()->where('code', $base.str_pad((string) $suffix, 2, '0', STR_PAD_LEFT))->exists()) {
            $suffix++;
        }

        return $base.str_pad((string) $suffix, 2, '0', STR_PAD_LEFT);
    }
}
