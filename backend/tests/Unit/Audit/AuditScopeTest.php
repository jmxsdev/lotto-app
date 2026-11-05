<?php

namespace Tests\Unit\Audit;

use App\Audit\AuditScope;
use Tests\TestCase;

class AuditScopeTest extends TestCase
{
    public function test_scope_vacio(): void
    {
        $scope = new AuditScope;

        $this->assertTrue($scope->isEmpty());
        $this->assertSame([
            'banca_id' => null,
            'grupo_id' => null,
            'agencia_id' => null,
            'taquilla_id' => null,
        ], $scope->toArray());
    }

    public function test_merge_conserva_valores_existentes_y_completa_los_vacios(): void
    {
        $base = new AuditScope(bancaId: 1, agenciaId: 3);

        $merged = $base->merge(new AuditScope(grupoId: 2, taquillaId: 4));

        $this->assertSame([
            'banca_id' => 1,
            'grupo_id' => 2,
            'agencia_id' => 3,
            'taquilla_id' => 4,
        ], $merged->toArray());
        $this->assertFalse($merged->isEmpty());
    }

    public function test_merge_no_borra_valores_con_null(): void
    {
        $base = new AuditScope(bancaId: 1, grupoId: 2, agenciaId: 3, taquillaId: 4);

        $merged = $base->merge(new AuditScope(bancaId: 9, grupoId: null));

        $this->assertSame(9, $merged->bancaId);
        $this->assertSame(2, $merged->grupoId);
        $this->assertSame(3, $merged->agenciaId);
        $this->assertSame(4, $merged->taquillaId);
    }

    public function test_merge_acepta_varios_scopes(): void
    {
        $merged = (new AuditScope(bancaId: 1))
            ->merge(new AuditScope(grupoId: 2), new AuditScope(taquillaId: 4));

        $this->assertSame(2, $merged->grupoId);
        $this->assertSame(4, $merged->taquillaId);
    }

    public function test_to_array_expone_las_cuatro_columnas(): void
    {
        $scope = new AuditScope(bancaId: 10, grupoId: 20, agenciaId: 30, taquillaId: 40);

        $this->assertSame([
            'banca_id' => 10,
            'grupo_id' => 20,
            'agencia_id' => 30,
            'taquilla_id' => 40,
        ], $scope->toArray());
        $this->assertFalse($scope->isEmpty());
    }

    public function test_el_scope_es_inmutable(): void
    {
        $base = new AuditScope(bancaId: 1);

        $base->merge(new AuditScope(bancaId: 2, grupoId: 3));

        $this->assertSame(1, $base->bancaId);
        $this->assertNull($base->grupoId);
        $this->assertFalse((new AuditScope(bancaId: 1))->merge(new AuditScope(grupoId: 3))->isEmpty());
    }
}
