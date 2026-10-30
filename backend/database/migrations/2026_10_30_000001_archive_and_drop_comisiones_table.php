<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Archiva las filas históricas del ledger de comisiones y elimina la tabla
 * activa (Modelo A — comisiones consultivas).
 *
 * Orden copy → verify → drop: el DDL de MySQL no es transaccional, así que
 * cualquier fallo antes del drop deja `comisiones` intacta. El archivo
 * conserva los ids originales para mantener estables las referencias de
 * auditoría y no declara FKs ni índices únicos (libera los nombres
 * `comisiones_*_foreign` para un futuro re-create).
 */
return new class extends Migration
{
    /**
     * Columnas copiadas del ledger al archivo (ids incluidos).
     *
     * @var list<string>
     */
    private array $columnas = [
        'id',
        'banca_id',
        'grupo_id',
        'taquilla_id',
        'periodo',
        'monto_comision',
        'estado',
        'fecha_inicio',
        'fecha_fin',
        'created_at',
        'updated_at',
    ];

    public function up(): void
    {
        // Ya archivada (replay de migraciones o fresh DB tras un drop previo).
        if (! Schema::hasTable('comisiones')) {
            return;
        }

        if (! Schema::hasTable('comisiones_archivo_2026')) {
            Schema::create('comisiones_archivo_2026', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('banca_id')->nullable()->index();
                $table->unsignedBigInteger('grupo_id')->nullable()->index();
                $table->unsignedBigInteger('taquilla_id')->nullable()->index();
                $table->string('periodo');
                $table->decimal('monto_comision', 12, 2);
                $table->string('estado', 20); // enum original → string, sin CHECK
                $table->date('fecha_inicio')->nullable();
                $table->date('fecha_fin')->nullable();
                $table->timestamps();
                $table->timestamp('archivado_at')->useCurrent();
                $table->index(
                    ['fecha_inicio', 'fecha_fin'],
                    'comisiones_archivo_2026_rango_index'
                );
            });
        }

        $origen = DB::table('comisiones')->count();
        $archivadas = DB::table('comisiones_archivo_2026')->count();

        // Un archivo no vacío con la tabla fuente aún presente solo puede
        // venir de una ejecución previa interrumpida: exigir revisión manual
        // en lugar de arriesgar una copia parcial o un drop silencioso.
        if ($archivadas > 0) {
            throw new RuntimeException(
                "El archivo comisiones_archivo_2026 ya tiene {$archivadas} fila(s) "
                ."y la tabla comisiones sigue existiendo ({$origen} fila(s)). "
                .'Revisar manualmente antes de reintentar la migración.'
            );
        }

        if ($origen > 0) {
            DB::table('comisiones_archivo_2026')->insertUsing(
                $this->columnas,
                DB::table('comisiones')->select($this->columnas)
            );
        }

        $archivadas = DB::table('comisiones_archivo_2026')->count();

        // Copia corta: no dropear la fuente.
        if ($archivadas < $origen) {
            throw new RuntimeException(
                "Copia incompleta: comisiones tiene {$origen} fila(s) y "
                ."comisiones_archivo_2026 tiene {$archivadas}. "
                .'La tabla comisiones se conserva intacta.'
            );
        }

        Schema::dropIfExists('comisiones');
    }

    /**
     * Recrea `comisiones` vacía con su forma original. No toca el archivo ni
     * restaura filas: eso es responsabilidad del dump pre-deploy.
     */
    public function down(): void
    {
        if (Schema::hasTable('comisiones')) {
            return;
        }

        Schema::create('comisiones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('banca_id')->nullable()->constrained()->onDelete('cascade');
            $table->foreignId('grupo_id')->nullable()->constrained()->onDelete('cascade');
            $table->foreignId('taquilla_id')->nullable()->constrained()->onDelete('cascade');
            $table->string('periodo');
            $table->decimal('monto_comision', 12, 2);
            $table->enum('estado', ['pendiente', 'pagado'])->default('pendiente');
            $table->date('fecha_inicio')->nullable();
            $table->date('fecha_fin')->nullable();
            $table->timestamps();
            $table->index(['fecha_inicio', 'fecha_fin']);
        });
    }
};
