<?php

namespace Tests\Unit;

use App\Models\Pago;
use InvalidArgumentException;
use Tests\TestCase;

class PagoMetodoPagoTest extends TestCase
{
    public function test_metodo_omitido_toma_el_default_efectivo()
    {
        $this->assertSame('efectivo', Pago::resolverMetodoPago(null, 'bs', 0));
    }

    public function test_pago_usd_fuerza_el_metodo_efectivo()
    {
        $this->assertSame('efectivo', Pago::resolverMetodoPago('transferencia', 'usd', 0));
        $this->assertSame('efectivo', Pago::resolverMetodoPago('pago_movil', 'bs', 12.50));
        $this->assertSame('efectivo', Pago::resolverMetodoPago('punto_venta', 'mixto', 5.00));
    }

    public function test_metodo_valido_se_conserva_en_pago_ves()
    {
        $this->assertSame('transferencia', Pago::resolverMetodoPago('transferencia', 'bs', 0));
        $this->assertSame('pago_movil', Pago::resolverMetodoPago('pago_movil', 'bs', 0));
        $this->assertSame('punto_venta', Pago::resolverMetodoPago('punto_venta', 'bs', 0));
        $this->assertSame('efectivo', Pago::resolverMetodoPago('efectivo', 'bs', 0));
    }

    public function test_metodo_invalido_es_rechazado()
    {
        $this->expectException(InvalidArgumentException::class);

        Pago::resolverMetodoPago('tarjeta', 'bs', 0);
    }

    public function test_const_metodos_pago_expone_el_enum_completo()
    {
        $this->assertSame(
            ['efectivo', 'transferencia', 'pago_movil', 'punto_venta'],
            Pago::METODOS_PAGO
        );
    }
}
