<?php

namespace Tests\Unit\Audit;

use App\Audit\AuditScope;
use App\Audit\AuditScopeResolver;
use App\Models\Agencia;
use App\Models\Banca;
use App\Models\Grupo;
use App\Models\Taquilla;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Tests\TestCase;

class AuditScopeResolverTest extends TestCase
{
    private AuditScopeResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolver = new AuditScopeResolver;
    }

    private function banca(int $id = 10): Banca
    {
        $banca = new Banca(['name' => 'Banca Norte']);
        $banca->id = $id;

        return $banca;
    }

    private function grupo(int $id = 20, int $bancaId = 10): Grupo
    {
        $grupo = new Grupo(['banca_id' => $bancaId]);
        $grupo->id = $id;

        return $grupo;
    }

    private function agencia(int $id = 30, int $grupoId = 20): Agencia
    {
        $agencia = new Agencia(['grupo_id' => $grupoId]);
        $agencia->id = $id;

        return $agencia;
    }

    private function taquilla(int $id = 40, int $grupoId = 20, int $agenciaId = 30): Taquilla
    {
        $taquilla = new Taquilla([
            'grupo_id' => $grupoId,
            'agencia_id' => $agenciaId,
        ]);
        $taquilla->id = $id;

        return $taquilla;
    }

    public function test_resuelve_scope_de_banca(): void
    {
        $this->assertSame([
            'banca_id' => 10,
            'grupo_id' => null,
            'agencia_id' => null,
            'taquilla_id' => null,
        ], $this->resolver->forModel($this->banca(10))->toArray());
    }

    public function test_resuelve_scope_de_grupo(): void
    {
        $this->assertSame([
            'banca_id' => 10,
            'grupo_id' => 20,
            'agencia_id' => null,
            'taquilla_id' => null,
        ], $this->resolver->forModel($this->grupo(20, 10))->toArray());
    }

    public function test_resuelve_banca_de_agencia_desde_su_grupo(): void
    {
        $agencia = $this->agencia(30, 20);
        $agencia->setRelation('grupo', $this->grupo(20, 10));

        $this->assertSame([
            'banca_id' => 10,
            'grupo_id' => 20,
            'agencia_id' => 30,
            'taquilla_id' => null,
        ], $this->resolver->forModel($agencia)->toArray());
    }

    public function test_resuelve_cadena_completa_de_taquilla(): void
    {
        $taquilla = $this->taquilla(40, 20, 30);
        $taquilla->setRelation('grupo', $this->grupo(20, 10));

        $this->assertSame([
            'banca_id' => 10,
            'grupo_id' => 20,
            'agencia_id' => 30,
            'taquilla_id' => 40,
        ], $this->resolver->forModel($taquilla)->toArray());
    }

    public function test_resuelve_scope_de_usuario(): void
    {
        $user = new User([
            'banca_id' => 10,
            'grupo_id' => 20,
            'agencia_id' => 30,
            'taquilla_id' => 40,
        ]);
        $user->id = 50;

        $this->assertSame([
            'banca_id' => 10,
            'grupo_id' => 20,
            'agencia_id' => 30,
            'taquilla_id' => 40,
        ], $this->resolver->forModel($user)->toArray());
    }

    public function test_for_actor_delega_en_el_modelo(): void
    {
        $user = new User([
            'banca_id' => 10,
            'grupo_id' => 20,
        ]);
        $user->id = 50;

        $this->assertSame(
            $this->resolver->forModel($user)->toArray(),
            $this->resolver->forActor($user)->toArray(),
        );
    }

    public function test_for_actor_sin_usuario_devuelve_scope_vacio(): void
    {
        $this->assertTrue($this->resolver->forActor(null)->isEmpty());
    }

    public function test_modelo_desconocido_devuelve_scope_vacio(): void
    {
        $model = new class extends Model
        {
            protected $guarded = [];
        };

        $this->assertTrue($this->resolver->forModel($model)->isEmpty());
    }

    public function test_modelo_generico_resuelve_el_padre_taquilla(): void
    {
        $taquilla = $this->taquilla(40, 20, 30);
        $taquilla->setRelation('grupo', $this->grupo(20, 10));

        $model = new class extends Model
        {
            protected $guarded = [];

            public function taquilla()
            {
                return $this->belongsTo(Taquilla::class);
            }
        };

        $model->setAttribute('taquilla_id', 40);
        $model->setRelation('taquilla', $taquilla);

        $this->assertSame([
            'banca_id' => 10,
            'grupo_id' => 20,
            'agencia_id' => 30,
            'taquilla_id' => 40,
        ], $this->resolver->forModel($model)->toArray());
    }

    public function test_merge_combina_los_niveles_sin_borrar_los_existentes(): void
    {
        $scope = $this->resolver->merge(
            new AuditScope(bancaId: 10),
            new AuditScope(grupoId: 20),
            new AuditScope(agenciaId: 30),
            new AuditScope(taquillaId: 40),
        );

        $this->assertSame([
            'banca_id' => 10,
            'grupo_id' => 20,
            'agencia_id' => 30,
            'taquilla_id' => 40,
        ], $scope->toArray());
    }
}
