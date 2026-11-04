<?php

namespace Tests\Feature;

use App\Models\Agencia;
use App\Models\Apuesta;
use App\Models\Banca;
use App\Models\CierreCaja;
use App\Models\Grupo;
use App\Models\Juego;
use App\Models\JuegoLimite;
use App\Models\Taquilla;
use App\Models\Ticket;
use App\Models\User;
use App\Support\Scope\AlcanceService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** WU-0c — matriz rol×entidad de `AlcanceService` sobre dos árboles (banca A/B). */
class AlcanceEntidadesTest extends TestCase
{
    use RefreshDatabase;

    private AlcanceService $service;

    private User $super;

    private User $masterA;

    private User $masterB;

    private User $sinRol;

    private array $arbolA = [];

    private array $arbolB = [];

    private User $bancaAUser;

    private User $grupoAUser;

    private User $agenciaAUser;

    private User $taquillaAUser;

    private User $agenciaA2User;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->service = app(AlcanceService::class);
        $this->super = User::factory()->withRole('super_master')->create();
        $this->masterA = User::factory()->withRole('master')->create();
        $this->masterB = User::factory()->withRole('master')->create();
        $this->sinRol = User::factory()->create();

        $this->arbolA = $this->crearArbol($this->masterA);
        $this->arbolB = $this->crearArbol($this->masterB);

        $vinculos = [
            'banca_id' => $this->arbolA['banca']->id,
            'grupo_id' => $this->arbolA['grupo']->id,
        ];

        $this->bancaAUser = User::factory()->withRole('banca')->create([
            'banca_id' => $vinculos['banca_id'],
        ]);
        $this->grupoAUser = User::factory()->withRole('grupo')->create($vinculos);
        $this->agenciaAUser = User::factory()->withRole('agencia')->create($vinculos + [
            'agencia_id' => $this->arbolA['agencia']->id,
        ]);
        $this->taquillaAUser = User::factory()->withRole('taquilla')->create($vinculos + [
            'agencia_id' => $this->arbolA['agencia']->id,
            'taquilla_id' => $this->arbolA['taquilla']->id,
        ]);

        // Segundo local del MISMO grupo: habilita la variante agencia→grupo.
        $agenciaA2 = Agencia::factory()->create(['grupo_id' => $vinculos['grupo_id']]);
        $this->agenciaA2User = User::factory()->withRole('agencia')->create($vinculos + [
            'agencia_id' => $agenciaA2->id,
        ]);
    }

    public function test_alcance_de_entidad_por_rol(): void
    {
        $todos = array_fill_keys(array_keys($this->arbolA), true);
        $ninguno = array_fill_keys(array_keys($this->arbolA), false);

        $matriz = [
            'super_master' => [$this->super, $todos],
            'master' => [$this->masterA, $todos],
            'banca' => [$this->bancaAUser, [
                'banca' => true, 'grupo' => true, 'agencia' => true, 'taquilla' => true,
                'apuesta' => true, 'ticket' => true, 'cierre' => true, 'limite' => true,
            ]],
            'grupo' => [$this->grupoAUser, [
                'banca' => true, 'grupo' => true, 'agencia' => true, 'taquilla' => true,
                'apuesta' => true, 'ticket' => true, 'cierre' => true, 'limite' => false,
            ]],
            'agencia' => [$this->agenciaAUser, [
                'banca' => true, 'grupo' => true, 'agencia' => true, 'taquilla' => true,
                'apuesta' => true, 'ticket' => true, 'cierre' => true, 'limite' => true,
            ]],
            'taquilla' => [$this->taquillaAUser, [
                'banca' => false, 'grupo' => false, 'agencia' => false, 'taquilla' => true,
                'apuesta' => true, 'ticket' => true, 'cierre' => true, 'limite' => false,
            ]],
            // `ticket` sin rol del contrato TICKETS conserva acceso (paridad con TicketController).
            'sin_rol' => [$this->sinRol, ['ticket' => true] + $ninguno],
        ];

        foreach ($matriz as $rol => [$actor, $esperado]) {
            foreach ($this->arbolA as $entidad => $modelo) {
                $this->assertSame(
                    $esperado[$entidad],
                    $this->service->alcanceDeEntidad($actor, $entidad, $modelo),
                    "alcanceDeEntidad({$entidad}) con rol {$rol}"
                );
            }
        }
    }

    public function test_alcance_de_entidad_aisla_el_arbol_ajeno_y_los_tipos(): void
    {
        foreach ($this->arbolB as $entidad => $modelo) {
            $this->assertFalse(
                $this->service->alcanceDeEntidad($this->masterA, $entidad, $modelo),
                "master A no debe alcanzar {$entidad} de la banca B"
            );
        }

        $this->assertFalse($this->service->alcanceDeEntidad($this->super, 'desconocida', $this->arbolA['banca']));
        // Entidad conocida con modelo de otro tipo: nunca concede alcance.
        $this->assertFalse($this->service->alcanceDeEntidad($this->super, 'banca', $this->arbolA['grupo']));
        $this->assertFalse($this->service->alcanceDeEntidad($this->super, 'taquilla', $this->arbolA['banca']));
    }

    public function test_puede_gestionar_por_rol(): void
    {
        $gestionables = array_intersect_key(
            $this->arbolA,
            array_flip(['banca', 'grupo', 'agencia', 'taquilla', 'limite'])
        );
        $todos = array_fill_keys(array_keys($gestionables), true);
        $ninguno = array_fill_keys(array_keys($gestionables), false);

        $matriz = [
            'super_master' => [$this->super, $todos],
            'master' => [$this->masterA, $todos],
            'banca' => [$this->bancaAUser, [
                'banca' => false, 'grupo' => true, 'agencia' => true, 'taquilla' => true, 'limite' => true,
            ]],
            'grupo' => [$this->grupoAUser, [
                'banca' => false, 'grupo' => true, 'agencia' => true, 'taquilla' => true, 'limite' => false,
            ]],
            'agencia' => [$this->agenciaAUser, [
                'banca' => false, 'grupo' => false, 'agencia' => true, 'taquilla' => true, 'limite' => false,
            ]],
            'taquilla' => [$this->taquillaAUser, $ninguno],
            'sin_rol' => [$this->sinRol, $ninguno],
        ];

        foreach ($matriz as $rol => [$actor, $esperado]) {
            foreach ($gestionables as $entidad => $modelo) {
                $this->assertSame(
                    $esperado[$entidad],
                    $this->service->puedeGestionar($actor, $entidad, $modelo),
                    "puedeGestionar({$entidad}) con rol {$rol}"
                );
            }
        }

        foreach (array_intersect_key($this->arbolB, $gestionables) as $entidad => $modelo) {
            $this->assertFalse(
                $this->service->puedeGestionar($this->masterA, $entidad, $modelo),
                "master A no debe gestionar {$entidad} de la banca B"
            );
        }

        // La variante agencia→grupo NO habilita gestión de taquillas ajenas:
        // el local A2 comparte grupo con la taquilla A, pero no es su dueño.
        $this->assertFalse(
            $this->service->puedeGestionar($this->agenciaA2User, 'taquilla', $this->arbolA['taquilla']),
            'agencia A2 no debe gestionar la taquilla de la agencia A'
        );
    }

    public function test_puede_operar_taquilla(): void
    {
        $propia = $this->arbolA['taquilla'];
        $ajena = $this->arbolB['taquilla'];

        $this->assertTrue($this->service->puedeOperarTaquilla($this->super, $propia));
        $this->assertTrue($this->service->puedeOperarTaquilla($this->super, $ajena));

        foreach ([$this->masterA, $this->bancaAUser, $this->grupoAUser, $this->agenciaAUser, $this->taquillaAUser] as $actor) {
            $this->assertTrue($this->service->puedeOperarTaquilla($actor, $propia));
            $this->assertFalse($this->service->puedeOperarTaquilla($actor, $ajena));
        }

        // Variante agencia→grupo: el local B2 cuelga del grupo de la taquilla A.
        $this->assertTrue($this->service->puedeOperarTaquilla($this->agenciaA2User, $propia));
        $this->assertFalse($this->service->puedeOperarTaquilla($this->agenciaA2User, $ajena));
        $this->assertFalse($this->service->puedeOperarTaquilla($this->sinRol, $propia));
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
        $juego = Juego::where('slug', 'lotto-activo')->firstOrFail();

        return [
            'banca' => $banca,
            'grupo' => $grupo,
            'agencia' => $agencia,
            'taquilla' => $taquilla,
            'apuesta' => Apuesta::create([
                'taquilla_id' => $taquilla->id,
                'juego_id' => $juego->id,
                'combinacion' => ['animal' => 'Perro', 'numero' => 5],
                'amount_bs' => 100,
                'amount_usd' => 0,
                'exchange_rate_applied' => 36.50,
                'total_bs_equivalent' => 100,
                'estado' => 'pendiente',
                'fecha_hora' => now(),
                'sorteo_hora' => now()->addHour(),
            ]),
            'ticket' => Ticket::create([
                'ticket_code' => 'TEST-'.uniqid(),
                'taquilla_id' => $taquilla->id,
                'estado' => 'pendiente',
            ]),
            'cierre' => CierreCaja::create([
                'taquilla_id' => $taquilla->id,
                'fecha_inicio' => now()->subDay(),
                'exchange_rate_cierre' => 36.50,
                'created_by' => $this->super->id,
            ]),
            'limite' => JuegoLimite::create([
                'juego_id' => $juego->id,
                'banca_id' => $banca->id,
                'moneda' => 'bs',
                'limite_minimo' => 1,
            ]),
        ];
    }
}
