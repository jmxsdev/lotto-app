<?php

namespace Tests\Unit;

use App\Support\Texto;
use Tests\TestCase;

class TextoTest extends TestCase
{
    public function test_normaliza_acento_y_mayusculas()
    {
        $this->assertSame('delfin', Texto::normalizar('Delfín'));
    }

    public function test_normaliza_espacios_acentos_y_mayusculas()
    {
        $this->assertSame('caiman', Texto::normalizar('  CAIMÁN  '));
    }

    public function test_normaliza_solo_mayusculas_sin_acentos()
    {
        $this->assertSame('perro', Texto::normalizar('PERRO'));
    }

    public function test_normaliza_vocales_acentuadas_y_enie()
    {
        $this->assertSame('aguila', Texto::normalizar('Águila'));
        $this->assertSame('cana', Texto::normalizar('Caña'));
    }

    public function test_normaliza_texto_ya_normalizado_es_idempotente()
    {
        $this->assertSame('delfin', Texto::normalizar(Texto::normalizar('Delfín')));
    }

    public function test_normaliza_vacio_y_solo_espacios()
    {
        $this->assertSame('', Texto::normalizar(''));
        $this->assertSame('', Texto::normalizar('   '));
    }
}