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
use Illuminate\Support\Collection;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * WU-0a — paridad del `AlcanceService` con las escaleras `rol → columna`
 * vigentes antes del refactor (#85).
 *
 * Se construyen DOS árboles independientes (banca A y banca B) para comprobar
 * que cada método devuelve los IDs del árbol del actor y nunca los del árbol
 * ajeno. `super_master` conserva el alcance global.
 */
class AlcanceServiceScopeTest extends TestCase
{
    use RefreshDatabase;

    private AlcanceService $service;

    private Juego $juego;

    private Banca $bancaA;

    private Banca $bancaB;

    private Grupo $grupoA;

    private Grupo $grupoB;

    private Agencia $agenciaA;

    private Agencia $agenciaB;

    private Taquilla $taquillaA;

    private Taquilla $taquillaB;

    private Apuesta $apuestaA;

    private Apuesta $apuestaB;

    private Ticket $ticketA;

    private Ticket $ticketB;

    private CierreCaja $cierreA;

    private CierreCaja $cierreB;

    private JuegoLimite $limiteBancaA;

    private JuegoLimite $limiteGrupoA;

    private JuegoLimite $limiteTaquillaA;

    private JuegoLimite $limiteBancaB;

    private User $super;

    private User $masterA;

    private User $masterB;

    private User $masterSinBancas;

    private User $bancaAUser;

    private User $grupoAUser;

    private User $agenciaAUser;

    private User $taquillaAUser;

    private User $sinRol;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->service = app(AlcanceService::class);
        $this->juego = Juego::where('slug', 'lotto-activo')->firstOrFail();

        $this->super = User::factory()->withRole('super_master')->create();
        $this->masterA = User::factory()->withRole('master')->create();
        $this->masterB = User::factory()->withRole('master')->create();
        $this->masterSinBancas = User::factory()->withRole('master')->create();
        $this->sinRol = User::factory()->create();

        $this->bancaA = Banca::factory()->create(['master_id' => $this->masterA->id]);
        $this->bancaB = Banca::factory()->create(['master_id' => $this->masterB->id]);

        $this->grupoA = Grupo::factory()->create(['banca_id' => $this->bancaA->id]);
        $this->grupoB = Grupo::factory()->create(['banca_id' => $this->bancaB->id]);

        $this->agenciaA = Agencia::factory()->create(['grupo_id' => $this->grupoA->id]);
        $this->agenciaB = Agencia::factory()->create(['grupo_id' => $this->grupoB->id]);

        $this->taquillaA = Taquilla::factory()->create([
            'grupo_id' => $this->grupoA->id,
            'agencia_id' => $this->agenciaA->id,
        ]);
        $this->taquillaB = Taquilla::factory()->create([
            'grupo_id' => $this->grupoB->id,
            'agencia_id' => $this->agenciaB->id,
        ]);

        $this->bancaAUser = User::factory()->withRole('banca')->create([
            'banca_id' => $this->bancaA->id,
        ]);
        $this->grupoAUser = User::factory()->withRole('grupo')->create([
            'banca_id' => $this->bancaA->id,
            'grupo_id' => $this->grupoA->id,
        ]);
        $this->agenciaAUser = User::factory()->withRole('agencia')->create([
            'banca_id' => $this->bancaA->id,
            'grupo_id' => $this->grupoA->id,
            'agencia_id' => $this->agenciaA->id,
        ]);
        $this->taquillaAUser = User::factory()->withRole('taquilla')->create([
            'banca_id' => $this->bancaA->id,
            'grupo_id' => $this->grupoA->id,
            'agencia_id' => $this->agenciaA->id,
            'taquilla_id' => $this->taquillaA->id,
        ]);

        $this->apuestaA = $this->crearApuesta($this->taquillaA);
        $this->apuestaB = $this->crearApuesta($this->taquillaB);

        $this->ticketA = $this->crearTicket($this->taquillaA);
        $this->ticketB = $this->crearTicket($this->taquillaB);

        $this->cierreA = $this->crearCierre($this->taquillaA);
        $this->cierreB = $this->crearCierre($this->taquillaB);

        $this->limiteBancaA = $this->crearLimite($this->bancaA);
        $this->limiteGrupoA = $this->crearLimite($this->bancaA, $this->grupoA);
        $this->limiteTaquillaA = $this->crearLimite($this->bancaA, $this->grupoA, $this->taquillaA);
        $this->limiteBancaB = $this->crearLimite($this->bancaB);
    }

    // ==================================================
    // Contrato de precedencia
    // ==================================================

    public function test_constantes_de_precedencia_respetan_el_contrato(): void
    {
        $this->assertSame(
            ['taquilla', 'agencia', 'grupo', 'banca', 'master', 'super_master'],
            AlcanceService::APUESTAS
        );
        $this->assertSame(
            ['taquilla', 'grupo', 'banca', 'master'],
            AlcanceService::TICKETS
        );
        $this->assertSame(
            ['super_master', 'taquilla', 'agencia', 'grupo', 'banca', 'master'],
            AlcanceService::POLICY
        );
        $this->assertSame(
            ['super_master', 'master', 'banca', 'grupo', 'agencia', 'taquilla'],
            AlcanceService::ENTIDADES
        );
        $this->assertSame(
            ['taquilla', 'super_master', 'master', 'agencia', 'banca', 'grupo'],
            AlcanceService::CIERRE_ITEM
        );
        $this->assertSame(
            ['super_master', 'master', 'banca', 'agencia', 'grupo'],
            AlcanceService::LIMITES
        );
        $this->assertSame(
            ['banca', 'master', 'grupo', 'agencia', 'super_master'],
            AlcanceService::JUEGO_VIS
        );
    }

    public function test_mensajes_de_vinculo_faltante_son_exactos(): void
    {
        $this->assertSame('No tienes una banca asociada.', AlcanceService::MENSAJE_SIN_BANCA);
        $this->assertSame('No tienes un grupo asociado.', AlcanceService::MENSAJE_SIN_GRUPO);
        $this->assertSame('No tienes una agencia asociada.', AlcanceService::MENSAJE_SIN_AGENCIA);
        $this->assertSame('No tienes una taquilla asociada.', AlcanceService::MENSAJE_SIN_TAQUILLA);
    }

    // ==================================================
    // Apuestas / tickets / cierres
    // ==================================================

    public function test_apply_to_apuestas_por_rol(): void
    {
        $this->assertScope($this->apuestasDe($this->taquillaAUser), $this->apuestaA, $this->apuestaB);
        $this->assertScope($this->apuestasDe($this->agenciaAUser), $this->apuestaA, $this->apuestaB);
        $this->assertScope($this->apuestasDe($this->grupoAUser), $this->apuestaA, $this->apuestaB);
        $this->assertScope($this->apuestasDe($this->bancaAUser), $this->apuestaA, $this->apuestaB);
        $this->assertScope($this->apuestasDe($this->masterA), $this->apuestaA, $this->apuestaB);

        $this->assertCount(Apuesta::count(), $this->apuestasDe($this->super));

        $sinBancas = $this->apuestasDe($this->masterSinBancas);
        $this->assertNotContains((int) $this->apuestaA->id, $sinBancas);
        $this->assertNotContains((int) $this->apuestaB->id, $sinBancas);

        $this->assertDeniega(
            fn () => $this->service->applyToApuestas(Apuesta::query(), $this->sinRol),
            'No autorizado.'
        );
    }

    public function test_apply_to_tickets_por_rol(): void
    {
        $this->assertScope($this->ticketsDe($this->taquillaAUser), $this->ticketA, $this->ticketB);
        $this->assertScope($this->ticketsDe($this->grupoAUser), $this->ticketA, $this->ticketB);
        $this->assertScope($this->ticketsDe($this->bancaAUser), $this->ticketA, $this->ticketB);
        $this->assertScope($this->ticketsDe($this->masterA), $this->ticketA, $this->ticketB);

        $this->assertCount(Ticket::count(), $this->ticketsDe($this->super));

        // Paridad: `agencia` queda fuera del contrato de filas de tickets.
        $agencia = $this->ticketsDe($this->agenciaAUser);
        $this->assertContains((int) $this->ticketA->id, $agencia);
        $this->assertContains((int) $this->ticketB->id, $agencia);
    }

    public function test_apply_to_cierres_por_rol(): void
    {
        $mensaje = 'No tienes permisos para ver cierres de caja.';

        $this->assertScope($this->cierresDe($this->taquillaAUser, $mensaje), $this->cierreA, $this->cierreB);
        $this->assertScope($this->cierresDe($this->agenciaAUser, $mensaje), $this->cierreA, $this->cierreB);
        $this->assertScope($this->cierresDe($this->grupoAUser, $mensaje), $this->cierreA, $this->cierreB);
        $this->assertScope($this->cierresDe($this->bancaAUser, $mensaje), $this->cierreA, $this->cierreB);
        $this->assertScope($this->cierresDe($this->masterA, $mensaje), $this->cierreA, $this->cierreB);

        $this->assertCount(CierreCaja::count(), $this->cierresDe($this->super, $mensaje));

        $this->assertDeniega(
            fn () => $this->service->applyToCierres(CierreCaja::query(), $this->sinRol, $mensaje),
            $mensaje
        );
    }

    public function test_apply_to_cierres_deniega_vinculos_faltantes(): void
    {
        $bancaSinVinculo = User::factory()->withRole('banca')->create();
        $grupoSinVinculo = User::factory()->withRole('grupo')->create(['banca_id' => $this->bancaA->id]);
        $agenciaSinVinculo = User::factory()->withRole('agencia')->create(['banca_id' => $this->bancaA->id]);
        $taquillaSinVinculo = User::factory()->withRole('taquilla')->create(['banca_id' => $this->bancaA->id]);
        $mensaje = 'No tienes permisos para ver cierres de caja.';

        $this->assertDeniega(
            fn () => $this->cierresDe($bancaSinVinculo, $mensaje),
            AlcanceService::MENSAJE_SIN_BANCA
        );
        $this->assertDeniega(
            fn () => $this->cierresDe($grupoSinVinculo, $mensaje),
            AlcanceService::MENSAJE_SIN_GRUPO
        );
        $this->assertDeniega(
            fn () => $this->cierresDe($agenciaSinVinculo, $mensaje),
            AlcanceService::MENSAJE_SIN_AGENCIA
        );
        $this->assertDeniega(
            fn () => $this->cierresDe($taquillaSinVinculo, $mensaje),
            AlcanceService::MENSAJE_SIN_TAQUILLA
        );
    }

    // ==================================================
    // Usuarios
    // ==================================================

    public function test_apply_to_usuarios_por_rol(): void
    {
        $mensaje = 'No tienes permisos para ver usuarios.';
        $creadoPorMasterSinBancas = User::factory()->withRole('taquilla')->create([
            'created_by' => $this->masterSinBancas->id,
        ]);

        $super = $this->usuariosDe($this->super, $mensaje);
        $this->assertContains((int) $this->bancaAUser->id, $super);
        $this->assertContains((int) $this->masterB->id, $super);

        $master = $this->usuariosDe($this->masterA, $mensaje);
        $this->assertContains((int) $this->bancaAUser->id, $master);
        $this->assertContains((int) $this->taquillaAUser->id, $master);
        $this->assertNotContains((int) $this->sinRol->id, $master);

        $this->assertContiene($this->usuariosDe($this->bancaAUser, $mensaje), $this->taquillaAUser);
        $this->assertContiene($this->usuariosDe($this->grupoAUser, $mensaje), $this->taquillaAUser);
        $this->assertContiene($this->usuariosDe($this->agenciaAUser, $mensaje), $this->taquillaAUser);

        $taquilla = $this->usuariosDe($this->taquillaAUser, $mensaje);
        $this->assertSame([(int) $this->taquillaAUser->id], array_map('intval', $taquilla));

        $sinBancas = $this->usuariosDe($this->masterSinBancas, $mensaje);
        $this->assertContains((int) $creadoPorMasterSinBancas->id, $sinBancas);
        $this->assertNotContains((int) $this->bancaAUser->id, $sinBancas);

        $this->assertDeniega(
            fn () => $this->usuariosDe($this->sinRol, $mensaje),
            $mensaje
        );
    }

    public function test_apply_to_usuarios_deniega_vinculos_faltantes(): void
    {
        $mensaje = 'No tienes permisos para ver usuarios.';
        $bancaSinVinculo = User::factory()->withRole('banca')->create();
        $grupoSinVinculo = User::factory()->withRole('grupo')->create(['banca_id' => $this->bancaA->id]);
        $agenciaSinVinculo = User::factory()->withRole('agencia')->create(['banca_id' => $this->bancaA->id]);

        $this->assertDeniega(
            fn () => $this->usuariosDe($bancaSinVinculo, $mensaje),
            AlcanceService::MENSAJE_SIN_BANCA
        );
        $this->assertDeniega(
            fn () => $this->usuariosDe($grupoSinVinculo, $mensaje),
            AlcanceService::MENSAJE_SIN_GRUPO
        );
        $this->assertDeniega(
            fn () => $this->usuariosDe($agenciaSinVinculo, $mensaje),
            AlcanceService::MENSAJE_SIN_AGENCIA
        );
    }

    // ==================================================
    // Entidades
    // ==================================================

    public function test_apply_to_entidades_grupo_agencia_y_taquilla(): void
    {
        $grupos = 'No tienes permiso para ver grupos.';
        $agencias = 'No tienes permiso para ver agencias.';
        $taquillas = 'No tienes permiso para ver taquillas.';

        $this->assertScope($this->entidadesDe($this->masterA, 'grupo', $grupos), $this->grupoA, $this->grupoB);
        $this->assertScope($this->entidadesDe($this->bancaAUser, 'grupo', $grupos), $this->grupoA, $this->grupoB);
        $this->assertScope($this->entidadesDe($this->grupoAUser, 'grupo', $grupos), $this->grupoA, $this->grupoB);
        $this->assertAmbos($this->entidadesDe($this->super, 'grupo', $grupos), $this->grupoA, $this->grupoB);
        $this->assertDeniega(
            fn () => $this->entidadesDe($this->agenciaAUser, 'grupo', $grupos),
            $grupos
        );

        $this->assertScope($this->entidadesDe($this->masterA, 'agencia', $agencias), $this->agenciaA, $this->agenciaB);
        $this->assertScope($this->entidadesDe($this->bancaAUser, 'agencia', $agencias), $this->agenciaA, $this->agenciaB);
        $this->assertScope($this->entidadesDe($this->grupoAUser, 'agencia', $agencias), $this->agenciaA, $this->agenciaB);
        $this->assertScope($this->entidadesDe($this->agenciaAUser, 'agencia', $agencias), $this->agenciaA, $this->agenciaB);
        $this->assertAmbos($this->entidadesDe($this->super, 'agencia', $agencias), $this->agenciaA, $this->agenciaB);
        $this->assertDeniega(
            fn () => $this->entidadesDe($this->taquillaAUser, 'agencia', $agencias),
            $agencias
        );

        $this->assertScope($this->entidadesDe($this->masterA, 'taquilla', $taquillas), $this->taquillaA, $this->taquillaB);
        $this->assertScope($this->entidadesDe($this->bancaAUser, 'taquilla', $taquillas), $this->taquillaA, $this->taquillaB);
        $this->assertScope($this->entidadesDe($this->grupoAUser, 'taquilla', $taquillas), $this->taquillaA, $this->taquillaB);
        $this->assertScope($this->entidadesDe($this->agenciaAUser, 'taquilla', $taquillas), $this->taquillaA, $this->taquillaB);
        $this->assertAmbos($this->entidadesDe($this->super, 'taquilla', $taquillas), $this->taquillaA, $this->taquillaB);
        $this->assertDeniega(
            fn () => $this->entidadesDe($this->taquillaAUser, 'taquilla', $taquillas),
            $taquillas
        );
    }

    public function test_apply_to_entidades_banca_y_limite(): void
    {
        $bancas = 'No tienes permiso para ver bancas.';
        $limites = 'No tienes permiso para ver límites.';

        $this->assertScope($this->entidadesDe($this->masterA, 'banca', $bancas), $this->bancaA, $this->bancaB);
        $this->assertAmbos($this->entidadesDe($this->super, 'banca', $bancas), $this->bancaA, $this->bancaB);
        $this->assertDeniega(
            fn () => $this->entidadesDe($this->bancaAUser, 'banca', $bancas),
            $bancas
        );

        $this->assertScope($this->entidadesDe($this->masterA, 'limite', $limites), $this->limiteBancaA, $this->limiteBancaB);
        $this->assertScope($this->entidadesDe($this->bancaAUser, 'limite', $limites), $this->limiteBancaA, $this->limiteBancaB);
        $this->assertScope($this->entidadesDe($this->agenciaAUser, 'limite', $limites), $this->limiteBancaA, $this->limiteBancaB);
        $this->assertAmbos($this->entidadesDe($this->super, 'limite', $limites), $this->limiteBancaA, $this->limiteBancaB);

        // Paridad con JuegoController: el actor grupo ve las filas de su grupo.
        $grupo = $this->entidadesDe($this->grupoAUser, 'limite', $limites);
        $this->assertContains((int) $this->limiteGrupoA->id, $grupo);
        $this->assertNotContains((int) $this->limiteBancaB->id, $grupo);

        $this->assertDeniega(
            fn () => $this->entidadesDe($this->taquillaAUser, 'limite', $limites),
            $limites
        );
    }

    public function test_apply_to_entidades_deniega_vinculos_faltantes_y_entidad_desconocida(): void
    {
        $bancaSinVinculo = User::factory()->withRole('banca')->create();
        $mensaje = 'No tienes permiso.';

        $this->assertDeniega(
            fn () => $this->entidadesDe($bancaSinVinculo, 'grupo', $mensaje),
            AlcanceService::MENSAJE_SIN_BANCA
        );
        $this->assertDeniega(
            fn () => $this->entidadesDe($this->sinRol, 'grupo', $mensaje),
            $mensaje
        );
        $this->assertDeniega(
            fn () => $this->entidadesDe($this->super, 'desconocida', $mensaje),
            $mensaje
        );
    }

    public function test_bancas_de_master(): void
    {
        $this->assertSame([(int) $this->bancaA->id], $this->ids($this->service->bancasDeMaster($this->masterA)));
        $this->assertSame([(int) $this->bancaB->id], $this->ids($this->service->bancasDeMaster($this->masterB)));
        $this->assertSame([], $this->ids($this->service->bancasDeMaster($this->bancaAUser)));
        $this->assertSame([], $this->ids($this->service->bancasDeMaster($this->masterSinBancas)));
    }

    public function test_denegar_lanza_403_con_el_mensaje_exacto(): void
    {
        $this->assertDeniega(
            fn () => $this->service->denegar('No autorizado.'),
            'No autorizado.'
        );
    }

    // ==================================================
    // Helpers
    // ==================================================

    private function crearApuesta(Taquilla $taquilla): Apuesta
    {
        return Apuesta::create([
            'taquilla_id' => $taquilla->id,
            'juego_id' => $this->juego->id,
            'combinacion' => ['animal' => 'Perro', 'numero' => 5],
            'amount_bs' => 100,
            'amount_usd' => 0,
            'exchange_rate_applied' => 36.50,
            'total_bs_equivalent' => 100,
            'estado' => 'pendiente',
            'fecha_hora' => now(),
            'sorteo_hora' => now()->addHour(),
        ]);
    }

    private function crearTicket(Taquilla $taquilla): Ticket
    {
        return Ticket::create([
            'ticket_code' => 'TEST-'.uniqid(),
            'taquilla_id' => $taquilla->id,
            'estado' => 'pendiente',
        ]);
    }

    private function crearCierre(Taquilla $taquilla): CierreCaja
    {
        return CierreCaja::create([
            'taquilla_id' => $taquilla->id,
            'fecha_inicio' => now()->subDay(),
            'exchange_rate_cierre' => 36.50,
            'created_by' => $this->super->id,
        ]);
    }

    private function crearLimite(Banca $banca, ?Grupo $grupo = null, ?Taquilla $taquilla = null): JuegoLimite
    {
        return JuegoLimite::create([
            'juego_id' => $this->juego->id,
            'banca_id' => $banca->id,
            'grupo_id' => $grupo?->id,
            'taquilla_id' => $taquilla?->id,
            'moneda' => 'bs',
            'limite_minimo' => 1,
        ]);
    }

    /**
     * @return array<int, int>
     */
    private function apuestasDe(User $actor): array
    {
        return $this->ids($this->service->applyToApuestas(Apuesta::query(), $actor)->pluck('id'));
    }

    /**
     * @return array<int, int>
     */
    private function ticketsDe(User $actor): array
    {
        return $this->ids($this->service->applyToTickets(Ticket::query(), $actor)->pluck('id'));
    }

    /**
     * @return array<int, int>
     */
    private function cierresDe(User $actor, string $mensaje): array
    {
        return $this->ids(
            $this->service->applyToCierres(CierreCaja::query(), $actor, $mensaje)->pluck('id')
        );
    }

    /**
     * @return array<int, int>
     */
    private function usuariosDe(User $actor, string $mensaje): array
    {
        return $this->ids(
            $this->service->applyToUsuarios(User::query(), $actor, $mensaje)->pluck('id')
        );
    }

    /**
     * @return array<int, int>
     */
    private function entidadesDe(User $actor, string $entidad, string $mensaje): array
    {
        $query = match ($entidad) {
            'banca' => Banca::query(),
            'grupo' => Grupo::query(),
            'agencia' => Agencia::query(),
            'taquilla' => Taquilla::query(),
            'limite' => JuegoLimite::query(),
            default => Banca::query(),
        };

        return $this->ids(
            $this->service->applyToEntidades($query, $actor, $entidad, $mensaje)->pluck('id')
        );
    }

    /**
     * @param  array{tipo: string, id: int}|null  $raiz
     * @return array<int, int>
     */
    private function visiblesDe(User $actor, string $tipo, ?array $raiz = null): array
    {
        return $this->ids($this->service->entidadesVisibles($actor, $tipo, $raiz)->pluck('id'));
    }

    /**
     * @param  Collection<int, mixed>|array<int, mixed>  $values
     * @return array<int, int>
     */
    private function ids($values): array
    {
        return array_map('intval', $values instanceof Collection ? $values->all() : $values);
    }

    /**
     * @param  array<int, int>  $ids
     */
    private function assertScope(array $ids, Apuesta|Ticket|CierreCaja|User|Banca|Grupo|Agencia|Taquilla|JuegoLimite $propio, Apuesta|Ticket|CierreCaja|User|Banca|Grupo|Agencia|Taquilla|JuegoLimite $ajeno): void
    {
        $this->assertContains((int) $propio->id, $ids);
        $this->assertNotContains((int) $ajeno->id, $ids);
    }

    /**
     * @param  array<int, int>  $ids
     */
    private function assertContiene(array $ids, Apuesta|Ticket|CierreCaja|User|Banca|Grupo|Agencia|Taquilla|JuegoLimite $modelo): void
    {
        $this->assertContains((int) $modelo->id, $ids);
    }

    /**
     * @param  array<int, int>  $ids
     */
    private function assertAmbos(array $ids, Apuesta|Ticket|CierreCaja|User|Banca|Grupo|Agencia|Taquilla|JuegoLimite $primero, Apuesta|Ticket|CierreCaja|User|Banca|Grupo|Agencia|Taquilla|JuegoLimite $segundo): void
    {
        $this->assertContains((int) $primero->id, $ids);
        $this->assertContains((int) $segundo->id, $ids);
    }

    private function assertEntidadGestionada(User $actor, bool $esperado): void
    {
        foreach ($this->entidadesA() as $entidad => $modelo) {
            $this->assertSame(
                $esperado,
                $this->service->alcanceDeEntidad($actor, $entidad, $modelo),
                "alcanceDeEntidad({$entidad}) para el rol {$actor->primaryRole()}"
            );
        }
    }

    /**
     * @return array<string, \Illuminate\Database\Eloquent\Model>
     */
    private function entidadesA(): array
    {
        return [
            'banca' => $this->bancaA,
            'grupo' => $this->grupoA,
            'agencia' => $this->agenciaA,
            'taquilla' => $this->taquillaA,
            'limite' => $this->limiteBancaA,
            'apuesta' => $this->apuestaA,
            'ticket' => $this->ticketA,
            'cierre' => $this->cierreA,
        ];
    }

    private function assertDeniega(callable $accion, string $mensaje): void
    {
        try {
            $accion();
            $this->fail('Se esperaba una denegación 403.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
            $this->assertSame($mensaje, $e->getMessage());
        }
    }
}
