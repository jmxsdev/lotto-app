<?php

namespace Tests\Unit;

use App\Models\Configuracion;
use App\Services\ConfiguracionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * F3 3.2 — ConfiguracionService (design §5, D5).
 *
 * Clave global `apuestas.vencimiento_sin_resultado` en la tabla
 * `configuraciones` (key único, banca_id=null) con valor `{"horas": 24}`.
 * Sin fila → fallback 24 h (no requiere migración de datos).
 */
class ConfiguracionServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_horas_vencimiento_default_24_sin_fila(): void
    {
        // D5: sin fila en configuraciones → fallback 24 h.
        $this->assertSame(0, Configuracion::count());

        $service = new ConfiguracionService;

        $this->assertSame(24, $service->horasVencimientoSinResultado());
        $this->assertSame(['horas' => 24], $service->ventanaVencimientoSinResultado());
    }

    public function test_set_ventana_crea_fila_global_con_key_unica(): void
    {
        // D5/§5: clave `apuestas.vencimiento_sin_resultado`, banca_id=null.
        $service = new ConfiguracionService;

        $service->setVentanaVencimiento(48);

        $fila = Configuracion::where('key', ConfiguracionService::CLAVE_VENCIMIENTO)->first();
        $this->assertNotNull($fila, 'La fila debe crearse con la clave global.');
        $this->assertNull($fila->banca_id, 'Configuración global: banca_id null.');
        $this->assertSame(['horas' => 48], $fila->value);
    }

    public function test_set_ventana_actualiza_sin_duplicar(): void
    {
        // El `key` es único: setear de nuevo actualiza la misma fila.
        $service = new ConfiguracionService;

        $service->setVentanaVencimiento(48);
        $service->setVentanaVencimiento(12);

        $this->assertSame(1, Configuracion::count(), 'Una sola fila global (key único).');
        $this->assertSame(12, $service->horasVencimientoSinResultado());
    }

    public function test_override_persistido_gana_al_default(): void
    {
        $service = new ConfiguracionService;
        $service->setVentanaVencimiento(6);

        $this->assertSame(6, $service->horasVencimientoSinResultado());
        $this->assertSame(['horas' => 6], $service->ventanaVencimientoSinResultado());
    }

    public function test_fila_sin_clave_horas_cae_al_default(): void
    {
        // Valor malformado (sin `horas`) → fallback 24 h defensivo.
        Configuracion::create([
            'key' => ConfiguracionService::CLAVE_VENCIMIENTO,
            'value' => ['dias' => 2],
            'banca_id' => null,
        ]);

        $service = new ConfiguracionService;

        $this->assertSame(24, $service->horasVencimientoSinResultado());
    }
}
