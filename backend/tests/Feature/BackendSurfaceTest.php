<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class BackendSurfaceTest extends TestCase
{
    public function test_checkrole_alias_is_not_registered(): void
    {
        $aliases = app('router')->getMiddleware();

        $this->assertArrayNotHasKey('checkrole', $aliases);
    }

    public function test_root_returns_json_404_with_accept_header(): void
    {
        $this->getJson('/')
            ->assertNotFound()
            ->assertHeader('Content-Type', 'application/json')
            ->assertJson(['message' => 'Recurso no encontrado.']);
    }

    public function test_root_returns_json_404_without_accept_header(): void
    {
        $this->get('/')
            ->assertNotFound()
            ->assertHeader('Content-Type', 'application/json');
    }

    public function test_legacy_admin_routes_are_not_registered(): void
    {
        $this->assertFalse(Route::has('admin.login'));
        $this->assertFalse(Route::has('admin.login.submit'));
        $this->assertFalse(Route::has('admin.logout'));
        $this->assertFalse(Route::has('admin.resultados.index'));
        $this->assertFalse(Route::has('admin.resultados.scrape'));
    }
}
