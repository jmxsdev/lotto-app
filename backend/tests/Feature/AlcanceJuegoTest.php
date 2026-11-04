<?php

namespace Tests\Feature;

use App\Models\Agencia;
use App\Models\Banca;
use App\Models\Grupo;
use App\Models\Taquilla;
use App\Models\User;
use App\Support\Scope\AlcanceService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * WU-4c — visibilidad de Juego (`JUEGO_VIS`) y alcance de comisiones.
 *
 * Fija el contrato que antes vivía en `JuegoController::entidadesVisiblesPorTipo`
 * y `ComisionController::bancasEnAlcance`: dos árboles independientes (A/B) para
 * comprobar que cada rol ve su descendencia y nunca el árbol ajeno, que las
 * intersecciones de raíz se respetan, y que el fail-closed conserva status y
 * mensaje exactos.
 */
class AlcanceJuegoTest extends TestCase
{
    use RefreshDatabase;

    private AlcanceService $service;

    private Banca $bancaA;

    private Banca $bancaB;

    private Grupo $grupoA1;

    private Grupo $grupoA2;

    private Grupo $grupoB;

    private Agencia $agenciaA1;

    private Agencia $agenciaA2;

    private Agencia $agenciaB;

    private Taquilla $taquillaA1;

    private Taquilla $taquillaA2;

    private Taquilla $taquillaB;

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

        $this->super = User::factory()->withRole('super_master')->create();
        $this->masterA = User::factory()->withRole('master')->create();
        $this->masterB = User::factory()->withRole('master')->create();
        $this->masterSinBancas = User::factory()->withRole('master')->create();
        $this->sinRol = User::factory()->create();

        $this->bancaA = Banca::factory()->create(['master_id' => $this->masterA->id]);
        $this->bancaB = Banca::factory()->create(['master_id' => $this->masterB->id]);

        $this->grupoA1 = Grupo::factory()->create(['banca_id' => $this->bancaA->id]);
        $this->grupoA2 = Grupo::factory()->create(['banca_id' => $this->bancaA->id]);
        $this->grupoB = Grupo::factory()->create(['banca_id' => $this->bancaB->id]);

        $this->agenciaA1 = Agencia::factory()->create(['grupo_id' => $this->grupoA1->id]);
        $this->agenciaA2 = Agencia::factory()->create(['grupo_id' => $this->grupoA2->id]);
        $this->agenciaB = Agencia::factory()->create(['grupo_id' => $this->grupoB->id]);

        $this->taquillaA1 = Taquilla::factory()->create([
            'grupo_id' => $this->grupoA1->id,
            'agencia_id' => $this->agenciaA1->id,
        ]);
        $this->taquillaA2 = Taquilla::factory()->create([
            'grupo_id' => $this->grupoA2->id,
            'agencia_id' => $this->agenciaA2->id,
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
            'grupo_id' => $this->grupoA1->id,
        ]);
        $this->agenciaAUser = User::factory()->withRole('agencia')->create([
            'banca_id' => $this->bancaA->id,
            'grupo_id' => $this->grupoA1->id,
            'agencia_id' => $this->agenciaA1->id,
        ]);
        $this->taquillaAUser = User::factory()->withRole('taquilla')->create([
            'banca_id' => $this->bancaA->id,
            'grupo_id' => $this->grupoA1->id,
            'agencia_id' => $this->agenciaA1->id,
            'taquilla_id' => $this->taquillaA1->id,
        ]);
    }

    // ==================================================
    // entidadesVisibles (contrato JUEGO_VIS)
    // ==================================================

    public function test_entidades_visibles_para_banca(): void
    {
        $this->assertIds([$this->bancaA->id], $this->visiblesDe($this->bancaAUser, 'banca'));
        $this->assertIds(
            [$this->grupoA1->id, $this->grupoA2->id],
            $this->visiblesDe($this->bancaAUser, 'grupo')
        );
        $this->assertIds(
            [$this->taquillaA1->id, $this->taquillaA2->id],
            $this->visiblesDe($this->bancaAUser, 'taquilla')
        );
    }

    public function test_entidades_visibles_para_master(): void
    {
        $this->assertIds([$this->bancaA->id], $this->visiblesDe($this->masterA, 'banca'));
        $this->assertIds(
            [$this->grupoA1->id, $this->grupoA2->id],
            $this->visiblesDe($this->masterA, 'grupo')
        );
        $this->assertIds(
            [$this->taquillaA1->id, $this->taquillaA2->id],
            $this->visiblesDe($this->masterA, 'taquilla')
        );

        // Master sin bancas ve NADA, nunca global.
        $this->assertIds([], $this->visiblesDe($this->masterSinBancas, 'banca'));
        $this->assertIds([], $this->visiblesDe($this->masterSinBancas, 'grupo'));
        $this->assertIds([], $this->visiblesDe($this->masterSinBancas, 'taquilla'));

        // La intersección de raíz nunca amplía el alcance del master.
        $this->assertIds(
            [],
            $this->visiblesDe($this->masterA, 'grupo', ['tipo' => 'banca', 'id' => $this->bancaB->id])
        );
    }

    public function test_entidades_visibles_para_grupo_y_agencia(): void
    {
        $this->assertIds([$this->bancaA->id], $this->visiblesDe($this->grupoAUser, 'banca'));
        $this->assertIds([$this->grupoA1->id], $this->visiblesDe($this->grupoAUser, 'grupo'));
        $this->assertIds([$this->taquillaA1->id], $this->visiblesDe($this->grupoAUser, 'taquilla'));

        $this->assertIds([$this->bancaA->id], $this->visiblesDe($this->agenciaAUser, 'banca'));
        $this->assertIds([$this->grupoA1->id], $this->visiblesDe($this->agenciaAUser, 'grupo'));
        $this->assertIds([$this->taquillaA1->id], $this->visiblesDe($this->agenciaAUser, 'taquilla'));
    }

    public function test_entidades_visibles_respetan_la_interseccion_de_raiz(): void
    {
        $raizBancaA = ['tipo' => 'banca', 'id' => $this->bancaA->id];
        $raizGrupoA1 = ['tipo' => 'grupo', 'id' => $this->grupoA1->id];

        $this->assertIds(
            [$this->grupoA1->id, $this->grupoA2->id],
            $this->visiblesDe($this->bancaAUser, 'grupo', $raizBancaA)
        );
        $this->assertIds(
            [$this->taquillaA1->id, $this->taquillaA2->id],
            $this->visiblesDe($this->bancaAUser, 'taquilla', $raizBancaA)
        );
        $this->assertIds(
            [$this->taquillaA1->id],
            $this->visiblesDe($this->bancaAUser, 'taquilla', $raizGrupoA1)
        );

        // Raíz del árbol ajeno: la intersección queda vacía.
        $this->assertIds(
            [],
            $this->visiblesDe($this->bancaAUser, 'grupo', ['tipo' => 'banca', 'id' => $this->bancaB->id])
        );
        $this->assertIds(
            [],
            $this->visiblesDe($this->grupoAUser, 'taquilla', ['tipo' => 'grupo', 'id' => $this->grupoA2->id])
        );
    }

    public function test_entidades_visibles_para_super_master_y_fail_closed(): void
    {
        $this->assertCount(Banca::count(), $this->visiblesDe($this->super, 'banca'));
        $this->assertCount(Grupo::count(), $this->visiblesDe($this->super, 'grupo'));
        $this->assertCount(Taquilla::count(), $this->visiblesDe($this->super, 'taquilla'));

        $bancaSeed = Banca::where('code', 'BT001')->firstOrFail();
        $this->assertIds([$bancaSeed->id, $this->bancaB->id, $this->bancaA->id], $this->visiblesDe($this->super, 'banca'));
        $this->assertIds([$this->grupoB->id], $this->visiblesDe($this->super, 'grupo', ['tipo' => 'banca', 'id' => $this->bancaB->id]));

        // Rol ausente o fuera de catálogo: colección vacía, sin excepción.
        $this->assertIds([], $this->visiblesDe($this->sinRol, 'banca'));
        $this->assertIds([], $this->visiblesDe($this->sinRol, 'grupo'));
        $this->assertIds([], $this->visiblesDe($this->sinRol, 'taquilla'));
        $this->assertIds([], $this->visiblesDe($this->taquillaAUser, 'taquilla'));
    }

    // ==================================================
    // idsPorTipo (expansión de alcance)
    // ==================================================

    public function test_ids_por_tipo_para_banca_y_master(): void
    {
        $this->assertIds([$this->bancaA->id], $this->idsDe($this->bancaAUser, 'banca'));
        $this->assertIds(
            [$this->grupoA1->id, $this->grupoA2->id],
            $this->idsDe($this->bancaAUser, 'grupo')
        );
        $this->assertIds(
            [$this->taquillaA1->id, $this->taquillaA2->id],
            $this->idsDe($this->bancaAUser, 'taquilla')
        );

        $this->assertIds([$this->bancaA->id], $this->idsDe($this->masterA, 'banca'));
        $this->assertIds(
            [$this->grupoA1->id, $this->grupoA2->id],
            $this->idsDe($this->masterA, 'grupo')
        );
        $this->assertIds(
            [$this->taquillaA1->id, $this->taquillaA2->id],
            $this->idsDe($this->masterA, 'taquilla')
        );
    }

    public function test_ids_por_tipo_master_sin_bancas_ve_nada(): void
    {
        $this->assertIds([], $this->idsDe($this->masterSinBancas, 'banca'));
        $this->assertIds([], $this->idsDe($this->masterSinBancas, 'grupo'));
        $this->assertIds([], $this->idsDe($this->masterSinBancas, 'taquilla'));
    }

    public function test_ids_por_tipo_para_grupo_y_agencia_conserva_el_orden_de_ramas(): void
    {
        // Rama banca del ladder: grupo cae en `[$actor->banca_id]`.
        $this->assertIds([$this->bancaA->id], $this->idsDe($this->grupoAUser, 'banca'));
        $this->assertIds([$this->grupoA1->id], $this->idsDe($this->grupoAUser, 'grupo'));
        $this->assertIds([$this->taquillaA1->id], $this->idsDe($this->grupoAUser, 'taquilla'));

        // `banca|agencia` se evalúa antes que `master` en el ladder original.
        $this->assertIds([$this->bancaA->id], $this->idsDe($this->agenciaAUser, 'banca'));
        $this->assertIds([$this->grupoA1->id], $this->idsDe($this->agenciaAUser, 'grupo'));
        $this->assertIds([$this->taquillaA1->id], $this->idsDe($this->agenciaAUser, 'taquilla'));
    }

    public function test_ids_por_tipo_para_super_master(): void
    {
        $this->assertCount(Banca::count(), $this->idsDe($this->super, 'banca'));
        $this->assertCount(Grupo::count(), $this->idsDe($this->super, 'grupo'));
        $this->assertCount(Taquilla::count(), $this->idsDe($this->super, 'taquilla'));

        $bancaSeed = Banca::where('code', 'BT001')->firstOrFail();
        $this->assertIds([$bancaSeed->id, $this->bancaB->id, $this->bancaA->id], $this->idsDe($this->super, 'banca'));
        $taquillaSeed = Taquilla::where('code', 'TT001')->firstOrFail();
        $this->assertIds([$taquillaSeed->id, $this->taquillaB->id, $this->taquillaA2->id, $this->taquillaA1->id], $this->idsDe($this->super, 'taquilla'));
    }

    public function test_ids_por_tipo_fail_closed_con_mensaje_exacto(): void
    {
        $mensaje = 'No tienes acceso a la entidad raíz del alcance.';

        foreach (['banca', 'grupo', 'taquilla'] as $singular) {
            $this->assertDeniega(fn () => $this->idsDe($this->sinRol, $singular), $mensaje);
            $this->assertDeniega(fn () => $this->idsDe($this->taquillaAUser, $singular), $mensaje);
        }
    }

    // ==================================================
    // Comisiones
    // ==================================================

    public function test_bancas_para_comisiones(): void
    {
        $this->assertSame(
            [(int) $this->bancaA->id],
            $this->ids($this->service->bancasParaComisiones($this->masterA))
        );
        $this->assertSame(
            [(int) $this->bancaB->id],
            $this->ids($this->service->bancasParaComisiones($this->masterB))
        );
        $this->assertSame([], $this->ids($this->service->bancasParaComisiones($this->masterSinBancas)));
        $this->assertNull($this->service->bancasParaComisiones($this->super));

        $mensaje = 'No autorizado: rol sin alcance de comisiones.';
        $this->assertDeniega(fn () => $this->service->bancasParaComisiones($this->bancaAUser), $mensaje);
        $this->assertDeniega(fn () => $this->service->bancasParaComisiones($this->sinRol), $mensaje);
    }

    // ==================================================
    // Helpers
    // ==================================================

    /**
     * @param  array{tipo: string, id: int}|null  $raiz
     * @return array<int, int>
     */
    private function visiblesDe(User $actor, string $tipo, ?array $raiz = null): array
    {
        return $this->ids($this->service->entidadesVisibles($actor, $tipo, $raiz)->pluck('id'));
    }

    /**
     * @return array<int, int>
     */
    private function idsDe(User $actor, string $singular): array
    {
        return $this->ids($this->service->idsPorTipo($actor, $singular));
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
     * @param  array<int, int>  $esperados
     * @param  array<int, int>  $reales
     */
    private function assertIds(array $esperados, array $reales): void
    {
        sort($esperados);
        sort($reales);

        $this->assertSame($esperados, $reales);
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
