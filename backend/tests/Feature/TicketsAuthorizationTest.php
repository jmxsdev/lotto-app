<?php

namespace Tests\Feature;

use App\Models\Apuesta;
use App\Models\Banca;
use App\Models\Grupo;
use App\Models\Juego;
use App\Models\Taquilla;
use App\Models\Ticket;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * BE-04 WU-3 — autorización de `/tickets` por capability (matriz final).
 *
 * `agencia` no recibe `create_apuesta` (d,v sin create), así que todas las
 * rutas de tickets le responden 403. Esto mantiene cerrada la fuga de filas:
 * `AlcanceService::applyToTickets()` no filtra tickets para `agencia`.
 *
 * Los cuatro roles operativos (master, banca, grupo, taquilla) conservan el
 * acceso efectivo previo; el scoping de filas no cambia.
 */
class TicketsAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private const MAC = 'AA:BB:CC:DD:EE:FF';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    /**
     * Usuario del rol pedido, enlazado a la jerarquía banca→grupo→taquilla.
     *
     * @return array{0: User, 1: Banca, 2: Grupo, 3: Taquilla}
     */
    private function contexto(string $rol): array
    {
        $banca = Banca::factory()->create();
        $grupo = Grupo::factory()->create(['banca_id' => $banca->id]);
        $taquilla = Taquilla::factory()->create([
            'grupo_id' => $grupo->id,
            'mac_address' => self::MAC,
            'active' => true,
        ]);

        $atributos = match ($rol) {
            'master' => [],
            'banca' => ['banca_id' => $banca->id],
            'grupo' => ['banca_id' => $banca->id, 'grupo_id' => $grupo->id],
            'taquilla' => [
                'banca_id' => $banca->id,
                'grupo_id' => $grupo->id,
                'taquilla_id' => $taquilla->id,
            ],
            default => [],
        };

        $user = User::factory()->withRole($rol)->create($atributos);

        if ($rol === 'master') {
            $banca->update(['master_id' => $user->id]);
        }

        return [$user, $banca, $grupo, $taquilla];
    }

    private function ticket(Taquilla $taquilla, string $codigo): Ticket
    {
        return Ticket::create([
            'ticket_code' => $codigo,
            'taquilla_id' => $taquilla->id,
            'total_bs' => 10,
            'total_usd' => 0,
            'estado' => 'pendiente',
        ]);
    }

    /**
     * Apuesta pendiente con sorteo futuro, anulable dentro de la ventana.
     */
    private function apuestaAnulable(Taquilla $taquilla, Ticket $ticket): Apuesta
    {
        $juego = Juego::where('slug', 'lotto-activo')->firstOrFail();

        return Apuesta::create([
            'taquilla_id' => $taquilla->id,
            'ticket_id' => $ticket->id,
            'juego_id' => $juego->id,
            'combinacion' => ['animal' => 'perro', 'numero' => 5],
            'amount_bs' => 10,
            'amount_usd' => 0,
            'exchange_rate_applied' => 36.50,
            'total_bs_equivalent' => 10,
            'estado' => 'pendiente',
            'fecha_hora' => now()->format('Y-m-d H:i:s'),
            'sorteo_hora' => now()->addHours(2)->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function rolesOperativos(): array
    {
        return [
            'master' => ['master'],
            'banca' => ['banca'],
            'grupo' => ['grupo'],
            'taquilla' => ['taquilla'],
        ];
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function rolesAdministrativos(): array
    {
        return [
            'master' => ['master'],
            'banca' => ['banca'],
            'grupo' => ['grupo'],
        ];
    }

    public function test_agencia_no_accede_a_ninguna_ruta_de_tickets(): void
    {
        $agencia = User::factory()->withRole('agencia')->create();
        $taquilla = Taquilla::factory()->create([
            'grupo_id' => Grupo::factory()->create()->id,
            'active' => true,
        ]);
        $ticket = $this->ticket($taquilla, 'AGENCIA-FORBIDDEN');

        $this->actingAs($agencia, 'sanctum')
            ->getJson('/api/v1/tickets')
            ->assertForbidden();

        $this->actingAs($agencia, 'sanctum')
            ->postJson('/api/v1/tickets', [
                'lines' => [
                    [
                        'juego_id' => Juego::where('slug', 'lotto-activo')->firstOrFail()->id,
                        'combinacion' => ['animal' => 'perro', 'numero' => 5],
                        'amount_bs' => 10,
                        'amount_usd' => 0,
                    ],
                ],
            ])
            ->assertForbidden();

        $this->actingAs($agencia, 'sanctum')
            ->getJson('/api/v1/tickets/ganadores?fecha='.now()->format('Y-m-d'))
            ->assertForbidden();

        $this->actingAs($agencia, 'sanctum')
            ->deleteJson('/api/v1/tickets/'.$ticket->id, ['motivo' => 'prueba'])
            ->assertForbidden();

        $this->assertNotSoftDeleted('tickets', ['id' => $ticket->id]);
    }

    #[DataProvider('rolesOperativos')]
    public function test_rol_operativo_lista_tickets_y_consulta_ganadores(string $rol): void
    {
        [$user] = $this->contexto($rol);

        if ($rol === 'taquilla') {
            $this->withHeaders(['X-Device-MAC' => self::MAC]);
        }

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/tickets?per_page=100')
            ->assertOk();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/tickets/ganadores?fecha='.now()->format('Y-m-d'))
            ->assertOk();
    }

    public function test_solo_taquilla_crea_tickets(): void
    {
        [$user, , , $taquilla] = $this->contexto('taquilla');
        $juego = Juego::where('slug', 'lotto-activo')->firstOrFail();

        $this->withHeaders(['X-Device-MAC' => self::MAC])
            ->actingAs($user, 'sanctum')
            ->postJson('/api/v1/tickets', [
                'lines' => [
                    [
                        'juego_id' => $juego->id,
                        'combinacion' => ['animal' => 'perro', 'numero' => 5],
                        'amount_bs' => 10,
                        'amount_usd' => 0,
                    ],
                ],
            ])
            ->assertCreated();

        $this->assertDatabaseHas('tickets', [
            'taquilla_id' => $taquilla->id,
            'estado' => 'pendiente',
        ]);
    }

    #[DataProvider('rolesAdministrativos')]
    public function test_roles_administrativos_pasan_el_permiso_pero_no_crean_tickets(string $rol): void
    {
        [$user] = $this->contexto($rol);
        $juego = Juego::where('slug', 'lotto-activo')->firstOrFail();

        // 403 del controlador ("Solo las taquillas..."), no del middleware de
        // autorización: prueba que el rol supera `permission:create_apuesta`.
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/tickets', [
                'lines' => [
                    [
                        'juego_id' => $juego->id,
                        'combinacion' => ['animal' => 'perro', 'numero' => 5],
                        'amount_bs' => 10,
                        'amount_usd' => 0,
                    ],
                ],
            ])
            ->assertForbidden()
            ->assertJsonPath('message', 'Solo las taquillas pueden crear tickets.');
    }

    #[DataProvider('rolesOperativos')]
    public function test_rol_operativo_anula_ticket_de_su_alcance(string $rol): void
    {
        [$user, , , $taquilla] = $this->contexto($rol);
        $ticket = $this->ticket($taquilla, 'ANULA-'.strtoupper($rol));
        $this->apuestaAnulable($taquilla, $ticket);

        if ($rol === 'taquilla') {
            $this->withHeaders(['X-Device-MAC' => self::MAC]);
        }

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/v1/tickets/'.$ticket->id, ['motivo' => 'prueba'])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.ticket_id', $ticket->id);

        $this->assertSoftDeleted('tickets', ['id' => $ticket->id]);
        $this->assertDatabaseHas('tickets', [
            'id' => $ticket->id,
            'estado' => 'anulada',
        ]);
    }
}
