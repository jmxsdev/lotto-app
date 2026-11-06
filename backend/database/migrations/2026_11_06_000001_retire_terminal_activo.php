<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Retira el juego `terminal-activo` del catálogo (21 → 20): la variante
 * comercial Terminal Activo / Terminal Trío vive como modalidad `terminal`
 * del Trío Activo.
 *
 * Idempotente: si el slug ya no existe, `up()` no hace nada. Antes de borrar
 * se verifica que no haya apuestas: `apuestas.juego_id` es cascade y borrar el
 * juego arrastraría ventas en silencio. El borrado de dependientes es
 * explícito y en orden para que el cambio de datos sea auditable.
 *
 * `down()` es un no-op deliberado (D4): los hijos solo se reconstruyen
 * duplicando el seeder retirado, y un `plugin_juegos.class_namespace`
 * apuntaría a una clase que ya no existe. El rollback real es `git revert`
 * del work unit más `php artisan db:seed`, no un rollback de esquema.
 */
return new class extends Migration
{
    public function up(): void
    {
        $id = DB::table('juegos')->where('slug', 'terminal-activo')->value('id');

        if ($id === null) {
            return; // Idempotente: el juego ya fue retirado.
        }

        if (DB::table('apuestas')->where('juego_id', $id)->exists()) {
            throw new RuntimeException('terminal-activo tiene apuestas; abortado para no perder ventas.');
        }

        foreach (['juego_limites', 'juego_horarios', 'juego_opciones', 'plugin_juegos', 'resultados', 'juego_auditoria'] as $tabla) {
            DB::table($tabla)->where('juego_id', $id)->delete();
        }

        DB::table('juegos')->where('id', $id)->delete();
    }

    public function down(): void
    {
        // No-op documentado (D4): el retiro es irreversible a nivel de datos.
    }
};
