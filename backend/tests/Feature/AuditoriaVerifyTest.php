<?php

namespace Tests\Feature;

use App\Audit\AuditChainHasher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * WU-12b-2 — `audit:verify`.
 *
 * The command validates archived NDJSON.gz files (checksum + JSON lines with
 * `event_uuid`) and, when `audit.hash_chain` is enabled, walks `audit_events`
 * by ascending id recomputing each hash. Every run records
 * `audit.chain_verified` as the system actor and exits 1 on any failure.
 */
class AuditoriaVerifyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        config([
            'audit.enabled' => true,
            'audit.hash_chain' => false,
            'audit.archive.disk' => 'local',
            'audit.retention.archive_path' => 'audit/archive',
            'audit.retention.connection' => DB::getDefaultConnection(),
        ]);
    }

    // Archives

    public function test_verify_archivo_valido_con_checksum_emite_evento_ok(): void
    {
        $this->archiveFixture('audit/archive/valid.ndjson.gz', $this->validNdjson());

        $this->artisan('audit:verify')->assertExitCode(0);

        $context = $this->latestChainVerifiedContext();

        $this->assertTrue($context['ok']);
        $this->assertSame(1, $context['archives']);
        $this->assertSame(0, $context['archive_failures']);
        $this->assertFalse($context['hash_chain']);
        $this->assertSame(0, $context['checked']);
        $this->assertNull($context['first_broken_id']);
        $this->assertFalse($context['strict']);
    }

    public function test_verify_archivo_corrupto_falla_y_emite_evento_ok_false(): void
    {
        $this->archiveFixture(
            'audit/archive/corrupt.ndjson.gz',
            $this->validNdjson(),
            checksum: str_repeat('0', 64),
        );

        $this->artisan('audit:verify')->assertExitCode(1);

        $context = $this->latestChainVerifiedContext();

        $this->assertFalse($context['ok']);
        $this->assertSame(0, $context['archives']);
        $this->assertSame(1, $context['archive_failures']);
        $this->assertFalse($context['hash_chain']);
    }

    public function test_verify_sin_checksum_falla_en_strict_y_advierte_sin_strict(): void
    {
        $this->archiveFixture(
            'audit/archive/no-checksum.ndjson.gz',
            $this->validNdjson(),
            withChecksum: false,
        );

        $this->artisan('audit:verify', ['--strict' => true])->assertExitCode(1);

        $strict = $this->latestChainVerifiedContext();

        $this->assertFalse($strict['ok']);
        $this->assertSame(0, $strict['archives']);
        $this->assertSame(1, $strict['archive_failures']);
        $this->assertTrue($strict['strict']);

        // Documented decision: without `--strict` a missing `.sha256` is a
        // warning, not a failure. The NDJSON is still validated, the file is
        // counted as verified and the command exits 0.
        $this->artisan('audit:verify')->assertExitCode(0);

        $lenient = $this->latestChainVerifiedContext();

        $this->assertTrue($lenient['ok']);
        $this->assertSame(1, $lenient['archives']);
        $this->assertSame(0, $lenient['archive_failures']);
        $this->assertFalse($lenient['strict']);
    }

    public function test_verify_linea_ndjson_invalida_falla(): void
    {
        $validLine = json_encode(['event_uuid' => (string) Str::uuid()], JSON_THROW_ON_ERROR);
        $ndjson = $validLine."\n".'{"event_uuid":'."\n";

        $this->archiveFixture('audit/archive/invalid.ndjson.gz', $ndjson);

        $this->artisan('audit:verify')->assertExitCode(1);

        $context = $this->latestChainVerifiedContext();

        $this->assertFalse($context['ok']);
        $this->assertSame(0, $context['archives']);
        $this->assertSame(1, $context['archive_failures']);
    }

    // Hash chain

    public function test_verify_hash_chain_ok_cuando_los_hashes_coinciden(): void
    {
        config(['audit.hash_chain' => true]);

        $this->seedChain([
            ['2026-01-01 12:00:00', 'seed-1'],
            ['2026-01-02 12:00:00', 'seed-2'],
            ['2026-01-03 12:00:00', 'seed-3'],
        ]);

        $this->artisan('audit:verify')->assertExitCode(0);

        $context = $this->latestChainVerifiedContext();

        $this->assertTrue($context['ok']);
        $this->assertTrue($context['hash_chain']);
        $this->assertSame(3, $context['checked']);
        $this->assertNull($context['first_broken_id']);
        $this->assertSame(0, $context['archives']);
        $this->assertSame(0, $context['archive_failures']);
    }

    public function test_verify_hash_chain_con_rango_siembra_el_hash_anterior(): void
    {
        config(['audit.hash_chain' => true]);

        $this->seedChain([
            ['2026-01-01 12:00:00', 'seed-1'],
            ['2026-01-02 12:00:00', 'seed-2'],
            ['2026-01-03 12:00:00', 'seed-3'],
        ]);

        // The first row inside the window must be compared against the hash of
        // the row before the window, not against null.
        $this->artisan('audit:verify', ['--from' => '2026-01-02'])->assertExitCode(0);

        $context = $this->latestChainVerifiedContext();

        $this->assertTrue($context['ok']);
        $this->assertSame(2, $context['checked']);
        $this->assertNull($context['first_broken_id']);
    }

    public function test_verify_hash_chain_rota_reporta_el_primer_id(): void
    {
        config(['audit.hash_chain' => true]);

        // Insert rows directly: the append-only triggers block UPDATE, so the
        // broken hash has to be written at insert time. Row index 1 is broken;
        // row 2 keeps chaining from the stored (wrong) hash.
        $ids = $this->seedChain(
            [
                ['2026-01-01 12:00:00', 'seed-1'],
                ['2026-01-02 12:00:00', 'seed-2'],
                ['2026-01-03 12:00:00', 'seed-3'],
            ],
            brokenIndex: 1,
        );

        $this->artisan('audit:verify')->assertExitCode(1);

        $context = $this->latestChainVerifiedContext();

        $this->assertFalse($context['ok']);
        $this->assertTrue($context['hash_chain']);
        $this->assertSame(2, $context['checked']);
        $this->assertSame($ids[1], $context['first_broken_id']);
    }

    // Helpers

    private function validNdjson(): string
    {
        return json_encode([
            'event_uuid' => (string) Str::uuid(),
            'action' => 'game.updated',
            'schema_version' => 1,
        ], JSON_THROW_ON_ERROR)."\n";
    }

    private function archiveFixture(
        string $path,
        string $ndjson,
        bool $withChecksum = true,
        ?string $checksum = null,
    ): string {
        $compressed = gzencode($ndjson);
        $storage = Storage::disk('local');

        $storage->put($path, $compressed);

        if ($withChecksum) {
            $storage->put($path.'.sha256', ($checksum ?? hash('sha256', $compressed))."\n");
        }

        return $path;
    }

    /**
     * Insert a chained sequence directly and sync `audit_chain_state` so the
     * `audit.chain_verified` event emitted afterwards appends correctly.
     *
     * @param  array<int, array{0: string, 1: string}>  $events  [occurred_at, uuid]
     * @return array<int, int> inserted ids
     */
    private function seedChain(array $events, ?int $brokenIndex = null): array
    {
        $hasher = new AuditChainHasher;
        $prevHash = null;
        $lastHash = null;
        $ids = [];

        foreach ($events as $index => [$occurredAt, $uuid]) {
            $row = $this->chainRow($uuid, $occurredAt);
            $hash = $index === $brokenIndex
                ? str_repeat('f', 64)
                : $hasher->hash($prevHash, $row);

            $ids[] = $this->insertRaw($row, $prevHash, $hash);
            $prevHash = $hash;
            $lastHash = $hash;
        }

        DB::table('audit_chain_state')->where('id', 1)->update([
            'last_event_id' => end($ids),
            'last_hash' => $lastHash,
            'updated_at' => now(),
        ]);

        return $ids;
    }

    /**
     * @return array<string, mixed>
     */
    private function chainRow(string $uuid, string $occurredAt): array
    {
        return [
            'event_uuid' => $uuid,
            'schema_version' => 1,
            'occurred_at' => $occurredAt,
            'actor_type' => 'system',
            'actor_id' => null,
            'actor_label' => 'command:audit:verify',
            'actor_roles' => [],
            'action' => 'game.updated',
            'entity_type' => 'juego',
            'entity_id' => 1,
            'banca_id' => null,
            'grupo_id' => null,
            'agencia_id' => null,
            'taquilla_id' => null,
            'before' => null,
            'after' => null,
            'context' => null,
            'reason_code' => null,
            'reason_text' => null,
            'ip' => null,
            'user_agent' => null,
            'request_id' => null,
            'job_id' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function insertRaw(array $row, ?string $prevHash, string $hash): int
    {
        foreach (['actor_roles', 'before', 'after', 'context'] as $column) {
            if ($row[$column] !== null) {
                $row[$column] = json_encode(
                    $row[$column],
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
                );
            }
        }

        $row['prev_hash'] = $prevHash;
        $row['hash'] = $hash;

        return (int) DB::table('audit_events')->insertGetId($row);
    }

    /**
     * @return array<string, mixed>
     */
    private function latestChainVerifiedContext(): array
    {
        $event = DB::table('audit_events')
            ->where('action', 'audit.chain_verified')
            ->orderByDesc('id')
            ->first();

        $this->assertNotNull($event, 'No se registró el evento audit.chain_verified.');
        $this->assertSame('system', $event->actor_type);
        $this->assertNull($event->ip);
        $this->assertNull($event->user_agent);

        return $this->decodeContext($event->context);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeContext(string $context): array
    {
        return json_decode($context, true, flags: JSON_THROW_ON_ERROR);
    }
}
