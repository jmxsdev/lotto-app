<?php

namespace Lotto\Kernel\Tests\Domain;

use Lotto\Kernel\Autorizacion\Domain\Capacidad;
use Lotto\Kernel\Autorizacion\Domain\CatalogoCapacidades;
use PHPUnit\Framework\TestCase;

final class CatalogoCapacidadesTest extends TestCase
{
    public function test_codigos_y_conteo(): void
    {
        $catalogo = CatalogoCapacidades::desde(['view_users', 'create_users']);

        $this->assertCount(2, $catalogo->todas());
        $this->assertSame(['view_users', 'create_users'], $catalogo->codigos());
        $this->assertTrue($catalogo->tiene('view_users'));
        $this->assertFalse($catalogo->tiene('manage_bancas'));
        $this->assertSame('create_users', (string) $catalogo->obtener('create_users'));
    }

    public function test_rechaza_capacidades_duplicadas(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        CatalogoCapacidades::desde(['view_users', 'view_users']);
    }

    public function test_obtener_desconocida_lanza(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        CatalogoCapacidades::desde(['view_users'])->obtener('manage_bancas');
    }

    public function test_capacidad_vacia_lanza(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Capacidad('');
    }
}
