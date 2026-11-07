<?php

namespace Tests\Feature;

use App\Models\Log;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Operational log view (`/logs`, WU-11b).
 *
 * Covers the paginator contract, the operational-action allowlist, date
 * filters interpreted in the app timezone, pagination order, per_page clamping
 * and unknown-key rejection.
 */
class LogsOperativoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function userWithRole(string $role): User
    {
        return User::factory()->withRole($role)->create();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function log(array $overrides = []): Log
    {
        $createdAt = $overrides['created_at'] ?? CarbonImmutable::parse('2026-11-05 12:00:00', 'UTC');
        unset($overrides['created_at']);

        $attributes = array_merge([
            'user_id' => null,
            'action' => 'activacion_taquilla',
            'details' => ['source' => 'phpunit'],
            'ip' => '190.0.0.1',
            'user_agent' => 'phpunit',
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ], $overrides);

        return Log::unguarded(fn () => Log::create($attributes));
    }

    public function test_super_master_lists_only_operational_actions_with_paginator_contract(): void
    {
        $super = $this->userWithRole('super_master');
        $operator = $this->userWithRole('master');

        $operational = $this->log([
            'user_id' => $operator->id,
            'action' => 'activacion_taquilla',
            'details' => ['taquilla' => 'T-01', 'mac' => 'AA:BB:CC:DD:EE:FF'],
        ]);
        $this->log(['action' => 'user.updated', 'user_id' => $operator->id]);

        $response = $this->actingAs($super, 'sanctum')->getJson('/api/v1/logs');

        $response->assertOk()->assertJsonStructure([
            'data',
            'current_page',
            'last_page',
            'per_page',
            'total',
        ]);

        $this->assertSame(1, $response->json('total'));
        $this->assertSame('activacion_taquilla', $response->json('data.0.action'));
        $this->assertSame($operational->id, $response->json('data.0.id'));
        $this->assertEquals(['taquilla' => 'T-01', 'mac' => 'AA:BB:CC:DD:EE:FF'], $response->json('data.0.details'));

        $actions = collect($response->json('data'))->pluck('action')->all();
        $this->assertSame(['activacion_taquilla'], $actions);

        $this->assertSame($operator->name, $response->json('data.0.user.name'));

        $userPayload = $response->json('data.0.user');
        $this->assertArrayNotHasKey('password', $userPayload);
        $this->assertArrayNotHasKey('remember_token', $userPayload);
        $this->assertArrayNotHasKey('clave_cierre', $userPayload);
    }

    public function test_master_can_list_and_banca_is_forbidden(): void
    {
        $master = $this->userWithRole('master');
        $banca = $this->userWithRole('banca');

        $this->log(['action' => 'scrape_resultados']);

        $this->actingAs($master, 'sanctum')
            ->getJson('/api/v1/logs')
            ->assertOk()
            ->assertJsonPath('total', 1);

        $this->actingAs($banca, 'sanctum')
            ->getJson('/api/v1/logs')
            ->assertStatus(403);
    }

    public function test_paginates_operational_logs_by_created_at_desc_and_id_desc(): void
    {
        $super = $this->userWithRole('super_master');

        $oldest = $this->log(['created_at' => CarbonImmutable::parse('2026-11-05 01:00:00', 'UTC')]);
        $middle = $this->log(['created_at' => CarbonImmutable::parse('2026-11-05 02:00:00', 'UTC')]);
        $newest = $this->log(['created_at' => CarbonImmutable::parse('2026-11-05 02:00:00', 'UTC')]);

        $firstPage = $this->actingAs($super, 'sanctum')
            ->getJson('/api/v1/logs?per_page=2&page=1');

        $firstPage->assertOk();
        $this->assertSame(1, $firstPage->json('current_page'));
        $this->assertSame(2, $firstPage->json('last_page'));
        $this->assertSame(2, $firstPage->json('per_page'));
        $this->assertSame(3, $firstPage->json('total'));
        $this->assertSame(
            [$newest->id, $middle->id],
            collect($firstPage->json('data'))->pluck('id')->all(),
        );

        $secondPage = $this->actingAs($super, 'sanctum')
            ->getJson('/api/v1/logs?per_page=2&page=2');

        $secondPage->assertOk();
        $this->assertSame(2, $secondPage->json('current_page'));
        $this->assertSame(2, $secondPage->json('last_page'));
        $this->assertSame([$oldest->id], collect($secondPage->json('data'))->pluck('id')->all());
    }

    public function test_action_filter_is_exact_and_non_operational_action_returns_empty(): void
    {
        $super = $this->userWithRole('super_master');

        $this->log(['action' => 'activacion_taquilla']);
        $scrape = $this->log(['action' => 'scrape_resultados']);

        $filtered = $this->actingAs($super, 'sanctum')
            ->getJson('/api/v1/logs?action=scrape_resultados');

        $filtered->assertOk();
        $this->assertSame([$scrape->id], collect($filtered->json('data'))->pluck('id')->all());

        $nonOperational = $this->actingAs($super, 'sanctum')
            ->getJson('/api/v1/logs?action=user.updated');

        $nonOperational->assertOk();
        $this->assertSame([], $nonOperational->json('data'));
        $this->assertSame(0, $nonOperational->json('total'));
    }

    public function test_from_and_to_interpret_plain_dates_in_app_timezone(): void
    {
        $super = $this->userWithRole('super_master');

        $inside = $this->log(['created_at' => CarbonImmutable::parse('2026-11-05 12:00:00', 'UTC')]);
        $this->log(['created_at' => CarbonImmutable::parse('2026-11-05 02:00:00', 'UTC')]);
        $this->log(['created_at' => CarbonImmutable::parse('2026-11-06 06:00:00', 'UTC')]);

        $response = $this->actingAs($super, 'sanctum')
            ->getJson('/api/v1/logs?from=2026-11-05&to=2026-11-05');

        $response->assertOk();
        $this->assertSame([$inside->id], collect($response->json('data'))->pluck('id')->all());
    }

    public function test_per_page_is_clamped_and_unknown_keys_are_rejected(): void
    {
        $super = $this->userWithRole('super_master');

        $this->log();

        $clamped = $this->actingAs($super, 'sanctum')
            ->getJson('/api/v1/logs?per_page=500');

        $clamped->assertOk();
        $this->assertSame(100, $clamped->json('per_page'));

        $this->actingAs($super, 'sanctum')
            ->getJson('/api/v1/logs?unknown=1')
            ->assertStatus(422)
            ->assertJsonValidationErrors('filters');
    }
}
