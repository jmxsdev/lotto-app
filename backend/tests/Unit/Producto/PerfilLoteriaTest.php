<?php

namespace Tests\Unit\Producto;

use App\Producto\PerfilLoteria;
use PHPUnit\Framework\TestCase;

final class PerfilLoteriaTest extends TestCase
{
    public function test_perfil_loteria_tiene_36_permisos_y_seis_niveles(): void
    {
        $perfil = PerfilLoteria::crear();

        $this->assertSame('loteria', $perfil->codigo);
        $this->assertCount(6, $perfil->niveles);
        $this->assertCount(36, $perfil->capacidades->todas());
    }

    public function test_matriz_conserva_los_conteos_actuales(): void
    {
        $perfil = PerfilLoteria::crear();

        $this->assertCount(35, $perfil->matriz->capacidadesDe('master'));
        $this->assertCount(24, $perfil->matriz->capacidadesDe('banca'));
        $this->assertCount(21, $perfil->matriz->capacidadesDe('grupo'));
        $this->assertCount(17, $perfil->matriz->capacidadesDe('agencia'));
        $this->assertCount(12, $perfil->matriz->capacidadesDe('taquilla'));
    }

    public function test_super_master_usa_bypass_y_no_esta_en_la_matriz(): void
    {
        $perfil = PerfilLoteria::crear();

        $this->assertTrue($perfil->superMasterBypass);
        $this->assertNotContains('super_master', $perfil->matriz->roles());
    }

    public function test_todas_las_capacidades_de_la_matriz_existen_en_el_catalogo(): void
    {
        $perfil = PerfilLoteria::crear();

        foreach ($perfil->matriz->matriz() as $capacidades) {
            foreach ($capacidades as $capacidad) {
                $this->assertTrue($perfil->capacidades->tiene($capacidad));
            }
        }
    }
}
