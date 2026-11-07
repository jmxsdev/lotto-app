<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

class AuditArchitectureTest extends TestCase
{
    public function test_el_middleware_se_registra_por_clase_en_el_grupo_v1(): void
    {
        $routes = File::get(base_path('routes/api.php'));

        $this->assertStringContainsString(
            'use App\Http\Middleware\CaptureAuditContext;',
            $routes,
        );

        $this->assertMatchesRegularExpression(
            "/Route::prefix\('v1'\)\s*->middleware\(\[CaptureAuditContext::class\]\)\s*->group\(/",
            $routes,
        );
    }

    public function test_bootstrap_no_registra_el_middleware_de_auditoria(): void
    {
        $this->assertStringNotContainsString(
            'CaptureAuditContext',
            File::get(base_path('bootstrap/app.php')),
        );
    }

    public function test_el_provider_liga_el_contexto_como_scoped(): void
    {
        $provider = File::get(app_path('Providers/AppServiceProvider.php'));

        $this->assertStringContainsString('use App\Audit\AuditContext;', $provider);
        $this->assertStringContainsString('$this->app->scoped(AuditContext::class', $provider);
    }

    public function test_solo_el_recorder_escribe_audit_events_y_no_hay_observer_global(): void
    {
        $writers = [];

        foreach (File::allFiles(app_path()) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $contents = File::get($file->getPathname());
            $path = str_replace('\\', '/', $file->getRelativePathname());

            if (str_contains($contents, 'AuditEvent::create')) {
                $writers[] = $path;
            }

            $this->assertStringNotContainsString(
                'AuditEvent::observe',
                $contents,
                "AuditEvent must not be registered with a global observer in {$path}.",
            );
        }

        $this->assertSame(['Audit/AuditRecorder.php'], $writers);
    }

    public function test_audit_event_no_usa_timestamps_ni_la_migracion_los_crea(): void
    {
        $model = File::get(app_path('Models/AuditEvent.php'));

        $this->assertStringContainsString('public $timestamps = false;', $model);

        $migrations = File::glob(base_path('database/migrations/*create_audit_events_table.php'));

        $this->assertNotEmpty($migrations);

        $migration = File::get($migrations[0]);

        $this->assertStringNotContainsString('created_at', $migration);
        $this->assertStringNotContainsString('updated_at', $migration);
    }

    public function test_el_trait_auditable_usa_el_recorder_y_no_escribe_directo(): void
    {
        $trait = File::get(app_path('Audit/Auditable.php'));

        $this->assertStringContainsString('AuditRecorder', $trait);
        $this->assertStringContainsString('AuditEventData::forModel', $trait);
        $this->assertStringNotContainsString('AuditEvent::create', $trait);
    }

    public function test_los_archivos_de_auditoria_no_referencian_el_legacy(): void
    {
        $files = array_merge(
            File::allFiles(app_path('Audit')),
            [new \SplFileInfo(app_path('Http/Middleware/CaptureAuditContext.php'))],
        );

        foreach ($files as $file) {
            $contents = File::get($file->getPathname());
            $path = $file->getPathname();

            $this->assertStringNotContainsString('FetchResultsJob', $contents, "Legacy job referenced in {$path}.");
            $this->assertStringNotContainsString('Http\\Controllers\\Admin', $contents, "Legacy admin referenced in {$path}.");
            $this->assertStringNotContainsString('routes/web.php', $contents, "Legacy web routes referenced in {$path}.");
        }
    }

    public function test_app_no_escribe_en_la_tabla_legacy_juego_auditoria(): void
    {
        $offenders = [];

        foreach (File::allFiles(app_path()) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            if (str_contains(File::get($file->getPathname()), 'JuegoAuditoria::create')) {
                $offenders[] = str_replace('\\', '/', $file->getRelativePathname());
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'JuegoAuditoria is read-only; game events must go through AuditRecorder.',
        );
    }

    public function test_las_escrituras_directas_a_audit_events_solo_viven_en_los_escritores_permitidos(): void
    {
        $allowed = [
            'Audit/AuditRecorder.php',
            'Console/Commands/AuditArchive.php',
            'Console/Commands/AuditBackfill.php',
        ];

        $writers = [];
        $pattern = "/table\('audit_events'\)[^;]*?->(?:insert|insertOrIgnore|update|delete)\s*\(/s";

        foreach (File::allFiles(app_path()) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $contents = File::get($file->getPathname());

            if (preg_match($pattern, $contents) === 1) {
                $writers[] = str_replace('\\', '/', $file->getRelativePathname());
            }
        }

        sort($writers);

        $this->assertSame(
            $allowed,
            $writers,
            'Only AuditRecorder, AuditBackfill and AuditArchive may write audit_events directly.',
        );
    }

    public function test_el_panel_de_auditoria_y_logs_no_usa_innerhtml(): void
    {
        $pages = [
            base_path('../panel/src/pages/auditoria.astro'),
            base_path('../panel/src/pages/logs.astro'),
        ];

        foreach ($pages as $page) {
            $this->assertFileExists($page);
            $this->assertStringNotContainsString(
                'innerHTML',
                File::get($page),
                basename($page).' must render audit data with textContent/DOM nodes, not innerHTML.',
            );
        }
    }
}
