<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Enforces append-only at the database level for `audit_events`.
 *
 * MySQL/MariaDB use `SIGNAL`; the DELETE guard allows the retention command
 * to opt in for its session with `SET @audit_retention = 1`. Other drivers are
 * skipped: production runs MySQL, and the grants file documents the same
 * guarantee for the app user. SQLite support would need a per-session bypass
 * that SQLite does not have.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        $this->createTrigger(
            'CREATE TRIGGER audit_events_no_update BEFORE UPDATE ON audit_events '
            ."FOR EACH ROW SIGNAL SQLSTATE '45000' "
            ."SET MESSAGE_TEXT = 'audit_events is append-only'"
        );

        $this->createTrigger(
            'CREATE TRIGGER audit_events_no_delete BEFORE DELETE ON audit_events '
            .'FOR EACH ROW BEGIN IF COALESCE(@audit_retention, 0) <> 1 THEN '
            ."SIGNAL SQLSTATE '45000' "
            ."SET MESSAGE_TEXT = 'audit_events is append-only'; "
            .'END IF; END'
        );
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::unprepared('DROP TRIGGER IF EXISTS audit_events_no_update');
        DB::unprepared('DROP TRIGGER IF EXISTS audit_events_no_delete');
    }

    private function createTrigger(string $sql): void
    {
        try {
            DB::unprepared($sql);
        } catch (QueryException $exception) {
            if (str_contains($exception->getMessage(), 'log_bin_trust_function_creators')) {
                throw new RuntimeException(
                    'MySQL rechazó el trigger append-only: el binlog está activo y el usuario no tiene SUPER. '
                    .'Ejecuta `SET GLOBAL log_bin_trust_function_creators = 1;` como root (o configura '
                    .'log_bin_trust_function_creators=1 en my.cnf) y vuelve a migrar. En producción usa un '
                    .'usuario de migración privilegiado o habilita la variable; las grants del usuario de la app '
                    .'siguen siendo la primera barrera.',
                    0,
                    $exception,
                );
            }

            throw $exception;
        }
    }
};
