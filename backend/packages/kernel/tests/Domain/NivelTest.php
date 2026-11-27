<?php

namespace Lotto\Kernel\Tests\Domain;

use Lotto\Kernel\Identidad\Domain\Nivel;
use PHPUnit\Framework\TestCase;

final class NivelTest extends TestCase
{
    public function test_los_seis_niveles_forman_la_cadena_correcta(): void
    {
        $this->assertNull(Nivel::SuperMaster->padre());
        $this->assertSame(Nivel::SuperMaster, Nivel::Master->padre());
        $this->assertSame(Nivel::Master, Nivel::Banca->padre());
        $this->assertSame(Nivel::Banca, Nivel::Grupo->padre());
        $this->assertSame(Nivel::Grupo, Nivel::Agencia->padre());
        $this->assertSame(Nivel::Agencia, Nivel::Taquilla->padre());
    }

    public function test_los_rangos_son_unicos_y_crecientes(): void
    {
        $rangos = array_map(fn (Nivel $nivel): int => $nivel->rango(), Nivel::cases());

        $this->assertSame([0, 1, 2, 3, 4, 5], $rangos);
    }

    public function test_la_jerarquia_es_inversa_al_rango(): void
    {
        $jerarquias = array_map(fn (Nivel $nivel): int => $nivel->jerarquia(), Nivel::cases());

        $this->assertSame([5, 4, 3, 2, 1, 0], $jerarquias);
    }

    public function test_cada_nivel_tiene_su_tabla_de_entidad(): void
    {
        $this->assertSame('super_masters', Nivel::SuperMaster->tablaEntidad());
        $this->assertSame('masters', Nivel::Master->tablaEntidad());
        $this->assertSame('bancas', Nivel::Banca->tablaEntidad());
        $this->assertSame('grupos', Nivel::Grupo->tablaEntidad());
        $this->assertSame('agencias', Nivel::Agencia->tablaEntidad());
        $this->assertSame('taquillas', Nivel::Taquilla->tablaEntidad());

        $tablas = array_map(fn (Nivel $nivel): string => $nivel->tablaEntidad(), Nivel::cases());
        $this->assertCount(6, array_unique($tablas));
    }

    public function test_solo_super_master_es_raiz(): void
    {
        $raices = array_values(array_filter(
            Nivel::cases(),
            fn (Nivel $nivel): bool => $nivel->padre() === null,
        ));

        $this->assertSame([Nivel::SuperMaster], $raices);
    }
}
