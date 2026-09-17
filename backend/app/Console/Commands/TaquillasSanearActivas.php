<?php

namespace App\Console\Commands;

use App\Models\Taquilla;
use Illuminate\Console\Command;

class TaquillasSanearActivas extends Command
{
    protected $signature = 'taquillas:sanear-activas {--apply : Aplica el saneo (default: dry-run, no escribe en la BD)}';

    protected $description = 'Sanea taquillas heredadas del bug de activación (active=true sin MAC): las marca inactivas; sin --apply solo muestra el conteo e IDs (dry-run)';

    public function handle(): int
    {
        $afectadas = Taquilla::query()
            ->where('active', true)
            ->whereNull('mac_address')
            ->orderBy('id')
            ->get();

        if (! $this->option('apply')) {
            $this->info(sprintf('Dry-run: %d taquilla(s) activa(s) sin MAC (se marcarían como inactivas).', $afectadas->count()));

            foreach ($afectadas->take(10) as $taquilla) {
                $this->line(sprintf('  - ID %d (%s)', $taquilla->id, $taquilla->code));
            }

            if ($afectadas->count() > 10) {
                $this->line(sprintf('... y %d más.', $afectadas->count() - 10));
            }

            return self::SUCCESS;
        }

        $actualizadas = Taquilla::query()
            ->where('active', true)
            ->whereNull('mac_address')
            ->update(['active' => false]);

        $this->info(sprintf('Saneo aplicado: %d taquilla(s) marcada(s) como inactiva(s).', $actualizadas));

        return self::SUCCESS;
    }
}