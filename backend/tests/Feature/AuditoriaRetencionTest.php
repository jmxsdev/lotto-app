<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * WU-12b-1 — retention commands.
 *
 * `audit:archive` is dry-run by default: it only counts. With `--apply` it
 * writes a verifiable NDJSON.gz archive (checksum + manifest), deletes the hot
 * rows through the retention connection and records `audit.retention_purged`.
 * `audit:prune` removes old archive files after verifying their checksum.
 */
class AuditoriaRetencionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        config([
            'audit.enabled' => true,
            'audit.archive.disk' => 'local',
            'audit.retention.archive_path' => 'audit/archive',
            // Same PDO as the test transaction: a separate connection would not
            // see rows inserted by RefreshDatabase's transaction.
            'audit.retention.connection' => DB::getDefaultConnection(),
        ]);
    }

    // audit:archive

    public function test_archive_dry_run_no_escribe_ni_borra_ni_emite_evento(): void
    {
        $this->auditEvent(CarbonImmutable::now()->subMonths(13));
        $this->auditEvent(CarbonImmutable::now()->subMonths(14));
        $this->auditEvent(CarbonImmutable::now());

        $this->artisan('audit:archive')->assertExitCode(0);

        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertSame(3, DB::table('audit_events')->count());
        $this->assertSame(
            0,
            DB::table('audit_events')->where('action', 'audit.retention_purged')->count(),
        );
    }

    public function test_archive_apply_escribe_ndjson_checksum_y_manifest_y_borra_los_viejos(): void
    {
        $oldest = $this->auditEvent(CarbonImmutable::now()->subMonths(20));
        $old = $this->auditEvent(CarbonImmutable::now()->subMonths(13));
        $fresh = $this->auditEvent(CarbonImmutable::now());

        $this->artisan('audit:archive', ['--apply' => true])->assertExitCode(0);

        $archives = $this->archiveFiles();

        $this->assertCount(1, $archives);

        $archive = $archives[0];
        $shaFile = $archive.'.sha256';
        $manifestFile = $archive.'.manifest.json';

        $this->assertTrue(Storage::disk('local')->exists($archive));
        $this->assertTrue(Storage::disk('local')->exists($shaFile));
        $this->assertTrue(Storage::disk('local')->exists($manifestFile));

        $compressed = Storage::disk('local')->get($archive);
        $sha256 = trim(Storage::disk('local')->get($shaFile));

        $this->assertSame(hash('sha256', $compressed), $sha256);

        $ndjson = gzdecode($compressed);
        $this->assertIsString($ndjson);

        $lines = array_values(array_filter(
            explode("\n", $ndjson),
            static fn (string $line): bool => $line !== '',
        ));

        $this->assertCount(2, $lines);

        foreach ($lines as $line) {
            $row = json_decode($line, true, flags: JSON_THROW_ON_ERROR);

            $this->assertArrayHasKey('event_uuid', $row);
            $this->assertMatchesRegularExpression(
                '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/',
                (string) $row['occurred_at'],
            );
        }

        $manifest = json_decode(
            Storage::disk('local')->get($manifestFile),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $this->assertSame(2, $manifest['count']);
        $this->assertSame($sha256, $manifest['sha256']);

        $this->assertDatabaseMissing('audit_events', ['id' => $oldest]);
        $this->assertDatabaseMissing('audit_events', ['id' => $old]);
        $this->assertDatabaseHas('audit_events', ['id' => $fresh]);

        $event = DB::table('audit_events')->where('action', 'audit.retention_purged')->sole();
        $context = json_decode($event->context, true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('system', $event->actor_type);
        $this->assertNull($event->ip);
        $this->assertNull($event->user_agent);
        $this->assertSame('archive', $context['operation']);
        $this->assertSame(2, $context['count']);
    }

    public function test_archive_apply_es_idempotente(): void
    {
        $this->auditEvent(CarbonImmutable::now()->subMonths(13));
        $this->auditEvent(CarbonImmutable::now()->subMonths(14));
        $fresh = $this->auditEvent(CarbonImmutable::now());

        $this->artisan('audit:archive', ['--apply' => true])->assertExitCode(0);
        $this->artisan('audit:archive', ['--apply' => true])->assertExitCode(0);

        $this->assertCount(1, $this->archiveFiles());
        $this->assertDatabaseHas('audit_events', ['id' => $fresh]);
        $this->assertSame(1, DB::table('audit_events')->where('action', 'audit.retention_purged')->count());
        $this->assertSame(2, DB::table('audit_events')->count());
    }

    // audit:prune

    public function test_prune_dry_run_no_borra_ningun_archivo(): void
    {
        $old = $this->archiveFixture('audit/archive/old.ndjson.gz', CarbonImmutable::now()->subYears(6));
        $fresh = $this->archiveFixture('audit/archive/fresh.ndjson.gz', CarbonImmutable::now()->subYears(1));

        $this->artisan('audit:prune')->assertExitCode(0);

        $this->assertTrue(Storage::disk('local')->exists($old));
        $this->assertTrue(Storage::disk('local')->exists($fresh));
    }

    public function test_prune_apply_borra_solo_los_viejos_y_emite_el_evento(): void
    {
        $old = $this->archiveFixture('audit/archive/old.ndjson.gz', CarbonImmutable::now()->subYears(6));
        $fresh = $this->archiveFixture('audit/archive/fresh.ndjson.gz', CarbonImmutable::now()->subYears(1));

        $this->artisan('audit:prune', ['--apply' => true])->assertExitCode(0);

        $this->assertFalse(Storage::disk('local')->exists($old));
        $this->assertFalse(Storage::disk('local')->exists($old.'.sha256'));
        $this->assertFalse(Storage::disk('local')->exists($old.'.manifest.json'));

        $this->assertTrue(Storage::disk('local')->exists($fresh));
        $this->assertTrue(Storage::disk('local')->exists($fresh.'.sha256'));
        $this->assertTrue(Storage::disk('local')->exists($fresh.'.manifest.json'));

        $event = DB::table('audit_events')->where('action', 'audit.retention_purged')->sole();
        $context = json_decode($event->context, true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('prune', $context['operation']);
        $this->assertSame(false, $context['dry_run']);
    }

    public function test_prune_con_checksum_invalido_no_borra_sin_force(): void
    {
        $old = $this->archiveFixture(
            'audit/archive/broken.ndjson.gz',
            CarbonImmutable::now()->subYears(6),
            'checksum-invalido',
        );

        $this->artisan('audit:prune', ['--apply' => true])->assertExitCode(1);

        $this->assertTrue(Storage::disk('local')->exists($old));

        $this->artisan('audit:prune', ['--apply' => true, '--force' => true])->assertExitCode(0);

        $this->assertFalse(Storage::disk('local')->exists($old));
    }

    // Helpers

    private function auditEvent(CarbonImmutable $occurredAt, string $action = 'game.updated'): int
    {
        return (int) DB::table('audit_events')->insertGetId([
            'event_uuid' => (string) Str::uuid(),
            'schema_version' => 1,
            'occurred_at' => $occurredAt->utc()->format('Y-m-d H:i:s'),
            'actor_type' => 'system',
            'action' => $action,
            'entity_type' => 'juego',
            'entity_id' => 1,
        ]);
    }

    /**
     * @return array<int, string>
     */
    private function archiveFiles(): array
    {
        return array_values(array_filter(
            Storage::disk('local')->files('audit/archive'),
            static fn (string $file): bool => str_ends_with($file, '.ndjson.gz'),
        ));
    }

    private function archiveFixture(
        string $path,
        CarbonImmutable $modifiedAt,
        ?string $checksum = null,
    ): string {
        $storage = Storage::disk('local');
        $compressed = gzencode('{"event_uuid":"fixture"}'."\n");

        $storage->put($path, $compressed);
        $storage->put($path.'.manifest.json', json_encode(['count' => 1], JSON_THROW_ON_ERROR)."\n");
        $storage->put(
            $path.'.sha256',
            ($checksum ?? hash('sha256', $compressed))."\n",
        );

        $timestamp = $modifiedAt->getTimestamp();

        foreach ([$path, $path.'.sha256', $path.'.manifest.json'] as $file) {
            touch($storage->path($file), $timestamp);
        }

        return $path;
    }
}
