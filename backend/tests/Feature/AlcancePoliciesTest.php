<?php

namespace Tests\Feature;

use App\Models\Agencia;
use App\Models\Banca;
use App\Models\CierreCaja;
use App\Models\Grupo;
use App\Models\Taquilla;
use App\Models\Ticket;
use App\Models\User;
use App\Policies\AgenciaPolicy;
use App\Policies\BancaPolicy;
use App\Policies\CierrePolicy;
use App\Policies\GrupoPolicy;
use App\Policies\TaquillaPolicy;
use App\Policies\TicketPolicy;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * WU-0b — registro explícito de las 6 policies y paridad de su delegación.
 *
 * La matriz de decisión de `AlcanceService` vive en `AlcanceEntidadesTest`;
 * aquí se comprueba que `Gate` resuelve cada policy registrada en
 * `AppServiceProvider` y que `view/update/delete/operar` devuelven la misma
 * decisión que el servicio (6 roles × 6 entidades sobre dos árboles A/B).
 */
class AlcancePoliciesTest extends TestCase
{
    use RefreshDatabase;

    private User $superMaster;

    private User $masterA;

    private User $bancaA;

    private User $grupoA;

    private User $agenciaA;

    private User $taquillaA;

    /**
     * Entidades propias del árbol A.
     *
     * @var array<string, mixed>
     */
    private array $arbolA = [];

    /**
     * Entidades de un árbol ajeno (banca B).
     *
     * @var array<string, mixed>
     */
    private array $arbolB = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->superMaster = User::factory()->withRole('super_master')->create();
        $this->masterA = User::factory()->withRole('master')->create();

        $this->arbolA = $this->crearArbol($this->masterA);
        $this->arbolB = $this->crearArbol(User::factory()->withRole('master')->create());

        $vinculos = [
            'banca_id' => $this->arbolA['banca']->id,
            'grupo_id' => $this->arbolA['grupo']->id,
            'agencia_id' => $this->arbolA['agencia']->id,
        ];

        $this->bancaA = User::factory()->withRole('banca')->create([
            'banca_id' => $vinculos['banca_id'],
        ]);
        $this->grupoA = User::factory()->withRole('grupo')->create([
            'banca_id' => $vinculos['banca_id'],
            'grupo_id' => $vinculos['grupo_id'],
        ]);
        $this->agenciaA = User::factory()->withRole('agencia')->create($vinculos);
        $this->taquillaA = User::factory()->withRole('taquilla')->create($vinculos + [
            'taquilla_id' => $this->arbolA['taquilla']->id,
        ]);
    }

    public function test_las_seis_policies_quedan_registradas_explicitamente(): void
    {
        $esperado = [
            Banca::class => BancaPolicy::class,
            Grupo::class => GrupoPolicy::class,
            Agencia::class => AgenciaPolicy::class,
            Taquilla::class => TaquillaPolicy::class,
            Ticket::class => TicketPolicy::class,
            CierreCaja::class => CierrePolicy::class,
        ];

        foreach ($esperado as $modelo => $policy) {
            $this->assertInstanceOf(
                $policy,
                Gate::getPolicyFor($modelo),
                "policy registrada para {$modelo}"
            );
        }
    }

    public function test_view_delega_en_alcance_de_entidad(): void
    {
        $todos = array_fill_keys(array_keys($this->arbolA), true);

        $matriz = [
            'super_master' => [$this->superMaster, $todos],
            'master' => [$this->masterA, $todos],
            'banca' => [$this->bancaA, $todos],
            'grupo' => [$this->grupoA, $todos],
            'agencia' => [$this->agenciaA, $todos],
            // Taquilla no ve niveles superiores ni el ticket/cierre ajenos.
            'taquilla' => [$this->taquillaA, [
                'banca' => false, 'grupo' => false, 'agencia' => false,
                'taquilla' => true, 'ticket' => true, 'cierre' => true,
            ]],
        ];

        foreach ($matriz as $rol => [$actor, $esperado]) {
            foreach ($this->arbolA as $entidad => $modelo) {
                $this->assertSame(
                    $esperado[$entidad],
                    Gate::forUser($actor)->allows('view', $modelo),
                    "view({$entidad}) con rol {$rol}"
                );
            }
        }
    }

    public function test_update_y_delete_delegan_en_puede_gestionar(): void
    {
        $todos = array_fill_keys(array_keys($this->arbolA), true);

        $matriz = [
            'super_master' => [$this->superMaster, $todos],
            'master' => [$this->masterA, $todos],
            'banca' => [$this->bancaA, [
                'banca' => false, 'grupo' => true, 'agencia' => true,
                'taquilla' => true, 'ticket' => true, 'cierre' => true,
            ]],
            'grupo' => [$this->grupoA, [
                'banca' => false, 'grupo' => true, 'agencia' => true,
                'taquilla' => true, 'ticket' => true, 'cierre' => true,
            ]],
            'agencia' => [$this->agenciaA, [
                'banca' => false, 'grupo' => false, 'agencia' => true,
                'taquilla' => true, 'ticket' => true, 'cierre' => true,
            ]],
            'taquilla' => [$this->taquillaA, [
                'banca' => false, 'grupo' => false, 'agencia' => false,
                'taquilla' => false, 'ticket' => true, 'cierre' => true,
            ]],
        ];

        foreach ($matriz as $rol => [$actor, $esperado]) {
            foreach ($this->arbolA as $entidad => $modelo) {
                foreach (['update', 'delete'] as $accion) {
                    $this->assertSame(
                        $esperado[$entidad],
                        Gate::forUser($actor)->allows($accion, $modelo),
                        "{$accion}({$entidad}) con rol {$rol}"
                    );
                }
            }
        }
    }

    public function test_arbol_ajeno_queda_denegado(): void
    {
        $actores = [
            'master' => $this->masterA,
            'banca' => $this->bancaA,
            'grupo' => $this->grupoA,
            'agencia' => $this->agenciaA,
            'taquilla' => $this->taquillaA,
        ];

        foreach ($actores as $rol => $actor) {
            foreach ($this->arbolB as $entidad => $modelo) {
                // Contrato TICKETS: `agencia` no restringe tickets (paridad con
                // TicketController::show), por eso conserva acceso global.
                if ($rol === 'agencia' && $entidad === 'ticket') {
                    $this->assertTrue(Gate::forUser($actor)->allows('view', $modelo));

                    continue;
                }

                foreach (['view', 'update', 'delete'] as $accion) {
                    $this->assertFalse(
                        Gate::forUser($actor)->allows($accion, $modelo),
                        "{$accion}({$entidad}) del árbol B con rol {$rol}"
                    );
                }
            }
        }
    }

    public function test_taquilla_policy_expone_operar(): void
    {
        $propia = $this->arbolA['taquilla'];
        $ajena = $this->arbolB['taquilla'];

        $this->assertTrue(Gate::forUser($this->superMaster)->allows('operar', $propia));
        $this->assertTrue(Gate::forUser($this->superMaster)->allows('operar', $ajena));

        foreach ([$this->masterA, $this->bancaA, $this->grupoA, $this->agenciaA, $this->taquillaA] as $actor) {
            $this->assertTrue(Gate::forUser($actor)->allows('operar', $propia));
            $this->assertFalse(Gate::forUser($actor)->allows('operar', $ajena));
        }
    }

    public function test_view_any_exige_un_rol_del_contrato_de_entidades(): void
    {
        $sinRol = User::factory()->create();

        foreach ([$this->superMaster, $this->masterA, $this->bancaA, $this->grupoA, $this->agenciaA, $this->taquillaA] as $actor) {
            $this->assertTrue(Gate::forUser($actor)->allows('viewAny', Banca::class));
        }

        $this->assertFalse(Gate::forUser($sinRol)->allows('viewAny', Banca::class));
    }

    /**
     * Árbol completo e independiente: banca → grupo → agencia → taquilla + filas.
     *
     * @return array<string, mixed>
     */
    private function crearArbol(User $master): array
    {
        $banca = Banca::factory()->create(['master_id' => $master->id]);
        $grupo = Grupo::factory()->create(['banca_id' => $banca->id]);
        $agencia = Agencia::factory()->create(['grupo_id' => $grupo->id]);
        $taquilla = Taquilla::factory()->create([
            'grupo_id' => $grupo->id,
            'agencia_id' => $agencia->id,
        ]);

        return [
            'banca' => $banca,
            'grupo' => $grupo,
            'agencia' => $agencia,
            'taquilla' => $taquilla,
            'ticket' => Ticket::create([
                'ticket_code' => 'TEST-'.uniqid(),
                'taquilla_id' => $taquilla->id,
                'estado' => 'pendiente',
            ]),
            'cierre' => CierreCaja::create([
                'taquilla_id' => $taquilla->id,
                'fecha_inicio' => now()->subDay(),
                'exchange_rate_cierre' => 36.50,
                'created_by' => $master->id,
            ]),
        ];
    }
}
