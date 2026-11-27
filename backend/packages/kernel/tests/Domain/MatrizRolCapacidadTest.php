<?php

namespace Lotto\Kernel\Tests\Domain;

use Lotto\Kernel\Autorizacion\Domain\CatalogoCapacidades;
use Lotto\Kernel\Autorizacion\Domain\MatrizRolCapacidad;
use Lotto\Kernel\Identidad\Domain\Rol;
use PHPUnit\Framework\TestCase;

final class MatrizRolCapacidadTest extends TestCase
{
    public function test_asigna_capacidades_por_rol(): void
    {
        $catalogo = CatalogoCapacidades::desde(['view_users', 'view_bancas', 'create_users']);

        $matriz = MatrizRolCapacidad::desde($catalogo, [
            'master' => ['view_users', 'view_bancas'],
            'banca' => ['view_users'],
        ]);

        $this->assertSame(['view_users', 'view_bancas'], $matriz->capacidadesDe(Rol::Master));
        $this->assertSame(['view_users'], $matriz->capacidadesDe('banca'));
        $this->assertTrue($matriz->tiene('master', 'view_users'));
        $this->assertFalse($matriz->tiene('master', 'create_users'));
        $this->assertSame(['master', 'banca'], $matriz->roles());
    }

    public function test_elimina_duplicados(): void
    {
        $catalogo = CatalogoCapacidades::desde(['view_users', 'view_bancas']);

        $matriz = MatrizRolCapacidad::desde($catalogo, [
            'master' => ['view_users', 'view_users', 'view_bancas'],
        ]);

        $this->assertSame(['view_users', 'view_bancas'], $matriz->capacidadesDe('master'));
    }

    public function test_rechaza_capacidad_desconocida(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $catalogo = CatalogoCapacidades::desde(['view_users']);

        MatrizRolCapacidad::desde($catalogo, [
            'master' => ['manage_bancas'],
        ]);
    }

    public function test_rechaza_rol_desconocido(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $catalogo = CatalogoCapacidades::desde(['view_users']);

        MatrizRolCapacidad::desde($catalogo, [
            'root' => ['view_users'],
        ]);
    }
}
