<?php

namespace Tests\Feature\Contract;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\TestCase;

/**
 * Contrato HTTP de autenticación.
 *
 * Congela la forma actual de la respuesta de login y /user. No prueba reglas
 * nuevas: si el refactor cambia un status, un mensaje o una clave del JSON,
 * estos tests fallan.
 */
final class AuthContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        // El contrato de login no incluye el throttle; lo desactivamos para
        // que varios casos de este archivo no consuman el límite.
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    private function login(User $user, string $password = 'password', bool $panel = true)
    {
        $headers = $panel ? ['X-Panel' => 'true'] : [];

        return $this->withHeaders($headers)->postJson('/api/v1/login', [
            'email' => $user->email,
            'password' => $password,
        ]);
    }

    public function test_login_panel_devuelve_token_user_y_role(): void
    {
        $user = User::factory()->withRole('master')->create();

        $response = $this->login($user);

        $response->assertOk()
            ->assertJsonStructure([
                'token',
                'role',
                'user' => ['id', 'name', 'email', 'role', 'permissions'],
            ])
            ->assertJsonPath('role', 'master')
            ->assertJsonPath('user.email', $user->email);

        $this->assertIsString($response->json('token'));
        $this->assertIsArray($response->json('user.permissions'));
    }

    public function test_login_super_master_devuelve_permissions_vacio(): void
    {
        $user = User::factory()->withRole('super_master')->create();

        $response = $this->login($user);

        $response->assertOk()
            ->assertJsonPath('role', 'super_master');

        $this->assertSame([], $response->json('user.permissions'));
    }

    public function test_login_con_credenciales_invalidas_devuelve_401(): void
    {
        $user = User::factory()->withRole('master')->create();

        $response = $this->login($user, 'wrong-password');

        $response->assertStatus(401)
            ->assertJsonPath('message', 'Credenciales incorrectas');
    }

    public function test_login_de_usuario_inactivo_devuelve_403(): void
    {
        $user = User::factory()->withRole('master')->create(['active' => false]);

        $response = $this->login($user);

        $response->assertStatus(403)
            ->assertJsonPath('message', 'Usuario inactivo');
    }

    public function test_login_de_usuario_sin_rol_devuelve_403(): void
    {
        $user = User::factory()->create();

        $response = $this->login($user);

        $response->assertStatus(403)
            ->assertJsonPath('message', 'Usuario sin rol asignado.');
    }

    public function test_login_de_panel_rechaza_taquilla(): void
    {
        $user = User::factory()->withRole('taquilla')->create();

        $response = $this->login($user);

        $response->assertStatus(403)
            ->assertJsonPath('message', 'Las taquillas deben usar la app de escritorio.');
    }

    public function test_user_endpoint_devuelve_perfil_y_permisos(): void
    {
        $user = User::factory()->withRole('master')->create();

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/user');

        $response->assertOk()
            ->assertJsonPath('email', $user->email)
            ->assertJsonPath('role', 'master');

        $this->assertIsArray($response->json('permissions'));
    }
}
