<?php

namespace Lotto\Kernel\Tests\Domain;

use Lotto\Kernel\Identidad\Domain\Nivel;
use Lotto\Kernel\Jerarquia\Domain\CatalogoNiveles;
use PHPUnit\Framework\TestCase;

final class CatalogoNivelesTest extends TestCase
{
    public function test_contiene_los_seis_niveles_en_orden(): void
    {
        $catalogo = CatalogoNiveles::seisNiveles();

        $this->assertCount(6, $catalogo);
        $this->assertSame([
            'super_master', 'master', 'banca', 'grupo', 'agencia', 'taquilla',
        ], $catalogo->codigos());
    }

    public function test_por_codigo_devuelve_el_nivel_del_catalogo(): void
    {
        $catalogo = CatalogoNiveles::seisNiveles();

        $this->assertSame(Nivel::Taquilla, $catalogo->porCodigo('taquilla'));
    }

    public function test_por_codigo_rechaza_un_nivel_fuera_del_catalogo(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        CatalogoNiveles::seisNiveles()->porCodigo('root');
    }

    public function test_solo_super_master_es_raiz(): void
    {
        $catalogo = CatalogoNiveles::seisNiveles();

        $this->assertSame([Nivel::SuperMaster], $catalogo->raices());
    }

    public function test_hijos_de_cada_nivel(): void
    {
        $catalogo = CatalogoNiveles::seisNiveles();

        $this->assertSame([Nivel::Master], $catalogo->hijosDe(Nivel::SuperMaster));
        $this->assertSame([Nivel::Banca], $catalogo->hijosDe(Nivel::Master));
        $this->assertSame([Nivel::Grupo], $catalogo->hijosDe(Nivel::Banca));
        $this->assertSame([Nivel::Agencia], $catalogo->hijosDe(Nivel::Grupo));
        $this->assertSame([Nivel::Taquilla], $catalogo->hijosDe(Nivel::Agencia));
        $this->assertSame([], $catalogo->hijosDe(Nivel::Taquilla));
    }
}
