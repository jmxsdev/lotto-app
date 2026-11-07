<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Preserves evidence when the referenced user/entity is deleted.
 *
 * Changing `cascade` to `nullOnDelete` keeps the evidence row (payments,
 * cash closures and legacy game audit) instead of destroying it. The columns
 * that carried evidence become nullable. `down()` only restores `NOT NULL` +
 * `cascade` when the column has no NULL rows; otherwise it leaves the safe
 * state in place and demands manual review (no data is deleted).
 */
return new class extends Migration
{
    /**
     * @var array<int, array{table: string, column: string, references: string}>
     */
    private const COLUMNS = [
        ['table' => 'pagos', 'column' => 'created_by', 'references' => 'users'],
        ['table' => 'cierres_caja', 'column' => 'created_by', 'references' => 'users'],
        ['table' => 'juego_auditoria', 'column' => 'user_id', 'references' => 'users'],
        ['table' => 'juego_auditoria', 'column' => 'juego_id', 'references' => 'juegos'],
    ];

    public function up(): void
    {
        foreach (self::COLUMNS as $definition) {
            if (! Schema::hasTable($definition['table'])) {
                continue;
            }

            $this->dropForeign($definition['table'], $definition['column']);

            Schema::table($definition['table'], function (Blueprint $table) use ($definition): void {
                $table->unsignedBigInteger($definition['column'])->nullable()->change();
            });

            Schema::table($definition['table'], function (Blueprint $table) use ($definition): void {
                $table->foreign($definition['column'])
                    ->references('id')
                    ->on($definition['references'])
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (self::COLUMNS as $definition) {
            if (! Schema::hasTable($definition['table'])) {
                continue;
            }

            // A NULL means the referenced row was already deleted while the
            // safe FK was in place. Restoring NOT NULL + cascade would require
            // fabricating data, so this migration refuses to do it.
            if (DB::table($definition['table'])->whereNull($definition['column'])->exists()) {
                continue;
            }

            $this->dropForeign($definition['table'], $definition['column']);

            Schema::table($definition['table'], function (Blueprint $table) use ($definition): void {
                $table->unsignedBigInteger($definition['column'])->change();
            });

            Schema::table($definition['table'], function (Blueprint $table) use ($definition): void {
                $table->foreign($definition['column'])
                    ->references('id')
                    ->on($definition['references'])
                    ->onDelete('cascade');
            });
        }
    }

    private function dropForeign(string $table, string $column): void
    {
        Schema::table($table, function (Blueprint $blueprint) use ($column): void {
            $blueprint->dropForeign([$column]);
        });
    }
};
