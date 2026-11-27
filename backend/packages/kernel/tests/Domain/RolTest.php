<?php

namespace Lotto\Kernel\Tests\Domain;

use Lotto\Kernel\Identidad\Domain\Rol;
use PHPUnit\Framework\TestCase;

final class RolTest extends TestCase
{
    public function test_cada_rol_mapea_a_su_nivel(): void
    {
        foreach (Rol::cases() as $rol) {
            $this->assertSame($rol->value, $rol->nivel()->value);
        }
    }

    public function test_los_roles_son_los_seis_del_negocio(): void
    {
        $codigos = array_map(fn (Rol $rol): string => $rol->value, Rol::cases());

        $this->assertSame([
            'super_master', 'master', 'banca', 'grupo', 'agencia', 'taquilla',
        ], $codigos);
    }

    public function test_desde_valida_el_codigo(): void
    {
        $this->assertSame(Rol::Taquilla, Rol::desde('taquilla'));
    }

    public function test_desde_rechaza_un_rol_desconocido(): void
    {
        $this->expectException(\ValueError::class);

        Rol::desde('root');
    }
}
