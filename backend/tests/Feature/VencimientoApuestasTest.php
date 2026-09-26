<?php

namespace Tests\Feature;

use App\Jobs\MarcarApuestasVencidasJob;
use App\Models\Apuesta;
use App\Models\Juego;
use App\Models\PluginJuego;
use App\Models\Resultado;
use App\Models\Taquilla;
use App\Plugins\Juegos\Animalitos;
use App\Plugins\Scrapers\BaseScraper;
use App\Services\ConfiguracionService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Scraper fake para el catch-up (3.4): devuelve un resultado configurable por
 * test (o nada). Cuenta las ejecuciones para probar que el job relanza
 * `ScrapeResultsJob` una vez por par (juego_id, fecha_sorteo).
 */
class FakeVencimientoScraper extends BaseScraper
{
    protected string $scraperName = 'FakeVencimientoScraper';

    protected ?Juego $juego;

    /** @var array{hora: string, numeros: array<string, mixed>}|array{} Resultado a devolver (vacío = sin resultado). */
    public static array $resultadoADevolver = [];

    public static int $ejecuciones = 0;

    public function __construct(?Juego $juego = null)
    {
        parent::__construct();
        $this->juego = $juego;
    }

    public function execute(?string $fecha = null): array
    {
        static::$ejecuciones++;

        return parent::execute($fecha);
    }

    protected function fetch(string $fecha): string
    {
        return '{}';
    }

    protected function parse(string $rawData): array
    {
        if (empty(static::$resultadoADevolver)) {
            return [];
        }

        $juego = $this->findJuegoOrFail([
            'slug' => $this->juego?->slug,
            'name' => $this->juego?->name,
        ]);

        return [[
            'juego_id' => $juego->id,
            'hora_sorteo' => static::$resultadoADevolver['hora'],
            'numeros_ganadores' => static::$resultadoADevolver['numeros'],
            'sorteo_id_externo' => null,
            'premios_detalle' => null,
        ]];
    }
}

/**
 * F3 3.4 — MarcarApuestasVencidasJob (REQ13/D5, design §5/§6).
 *
 * Vencimiento: `pendiente` sin `resultado_id` tras la ventana (24 h por
 * defecto, configurable) y tras el reintento de búsqueda → `vencido`.
 * Catch-up: antes de vencer, el job relanza `ScrapeResultsJob` por par
 * (juego_id, fecha_sorteo); si el resultado aparece, la apuesta se liquida
 * (ganadora/perdida) y NO pasa a vencido.
 */
class VencimientoApuestasTest extends TestCase
{
    use RefreshDatabase;

    private Juego $juego;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        FakeVencimientoScraper::$ejecuciones = 0;
        FakeVencimientoScraper::$resultadoADevolver = [];

        $this->juego = Juego::create([
            'name' => 'Juego Vencimiento',
            'slug' => 'juego-vencimiento',
            'type' => 'animalitos',
            'config' => ['premio_multiplo' => 30, 'premios' => ['base' => 30, 'modalidades' => [], 'comodines' => []]],
            'requires_scraper' => true,
            'scraper_url' => 'https://fake.test/resultados/',
            'scraper_class' => FakeVencimientoScraper::class,
            'active' => true,
        ]);

        PluginJuego::firstOrCreate(
            ['juego_id' => $this->juego->id],
            ['class_namespace' => Animalitos::class, 'version' => '1.0.0', 'active' => true]
        );
    }

    private function apuestaVieja(string $hora = '13:00', int $diasAtras = 2, ?int $resultadoId = null, string $estado = 'pendiente'): Apuesta
    {
        $taquilla = Taquilla::factory()->create();
        $fecha = now()->subDays($diasAtras)->format('Y-m-d');

        return Apuesta::create([
            'taquilla_id' => $taquilla->id,
            'juego_id' => $this->juego->id,
            'resultado_id' => $resultadoId,
            'combinacion' => json_encode(['animal' => 'Delfín', 'numero' => 0]),
            'amount_bs' => 10,
            'amount_usd' => 0,
            'exchange_rate_applied' => 36.50,
            'total_bs_equivalent' => 10,
            'estado' => $estado,
            'fecha_hora' => $fecha.' '.$hora.':00',
            'sorteo_hora' => $fecha.' '.$hora.':00',
        ]);
    }

    public function test_job_vence_apuesta_sin_resultado_tras_la_ventana(): void
    {
        // REQ13: sin resultado tras ventana + reintento → `vencido`.
        $apuesta = $this->apuestaVieja();

        (new MarcarApuestasVencidasJob)->handle();

        $apuesta->refresh();
        $this->assertSame('vencido', $apuesta->estado);
        $this->assertSame(1, FakeVencimientoScraper::$ejecuciones,
            'El job relanza la búsqueda (catch-up) antes de vencer.');
    }

    public function test_job_no_vence_apuesta_dentro_de_la_ventana(): void
    {
        // D5: sorteo hace 10 h (< 24 h) → sigue pendiente.
        $apuesta = $this->apuestaVieja('13:00', 0);
        Apuesta::where('id', $apuesta->id)->update(['sorteo_hora' => now()->subHours(10)]);

        (new MarcarApuestasVencidasJob)->handle();

        $apuesta->refresh();
        $this->assertSame('pendiente', $apuesta->estado);
    }

    public function test_job_catchup_relanza_busqueda_y_evita_el_vencimiento(): void
    {
        // REQ13: el reintento encuentra el resultado → la cadena liquida
        // (ganadora) y la apuesta NO pasa a vencido.
        FakeVencimientoScraper::$resultadoADevolver = [
            'hora' => '13:00',
            'numeros' => ['numero' => 0, 'nombre_animal' => 'Delfin'],
        ];

        $apuesta = $this->apuestaVieja();

        (new MarcarApuestasVencidasJob)->handle();

        $apuesta->refresh();
        $this->assertSame('ganadora', $apuesta->estado,
            'El resultado apareció: se liquida ganadora, no vence (REQ13).');
        $this->assertNotNull($apuesta->resultado_id);

        $resultados = Resultado::where('juego_id', $this->juego->id)->get();
        $this->assertCount(1, $resultados, 'El catch-up persistió el resultado del día.');
        $this->assertSame(1, FakeVencimientoScraper::$ejecuciones);
    }

    public function test_job_respeta_la_ventana_configurada(): void
    {
        // REQ13: ventana configurable — 48 h → una apuesta de 30 h NO vence.
        app(ConfiguracionService::class)->setVentanaVencimiento(48);
        $apuesta = $this->apuestaVieja();
        Apuesta::where('id', $apuesta->id)->update(['sorteo_hora' => now()->subHours(30)]);

        (new MarcarApuestasVencidasJob)->handle();

        $apuesta->refresh();
        $this->assertSame('pendiente', $apuesta->estado,
            '30 h < 48 h configuradas: aún dentro de la ventana.');
    }

    public function test_job_relanza_una_sola_vez_por_par_juego_fecha(): void
    {
        // §5: agrupa candidatas por (juego_id, fecha_sorteo) → UNA búsqueda
        // por par, aunque haya varias apuestas del mismo día.
        $this->apuestaVieja('13:00');
        $this->apuestaVieja('16:30');

        (new MarcarApuestasVencidasJob)->handle();

        $this->assertSame(1, FakeVencimientoScraper::$ejecuciones,
            'Dos apuestas del mismo (juego, fecha) comparten un solo reintento.');

        $this->assertSame(2, Apuesta::where('estado', 'vencido')->count(),
            'Sin resultado, ambas apuestas del par vencen.');
    }

    public function test_job_no_toca_apuestas_ya_liquidadas(): void
    {
        // Idempotencia: `pendiente` sin resultado_id es la única candidata;
        // una apuesta legacy con resultado_id (o ya vencida) no se modifica.
        $resultado = Resultado::create([
            'juego_id' => $this->juego->id,
            'fecha_sorteo' => now()->subDays(2)->format('Y-m-d'),
            'hora_sorteo' => '13:00',
            'numeros_ganadores' => ['numero' => 0, 'nombre_animal' => 'Delfin'],
        ]);

        $liquidadas = $this->apuestaVieja('13:00', 2, $resultado->id, 'ganadora');
        $vencida = $this->apuestaVieja('13:00', 2, null, 'vencido');

        (new MarcarApuestasVencidasJob)->handle();

        $liquidadas->refresh();
        $vencida->refresh();
        $this->assertSame('ganadora', $liquidadas->estado, 'Legacy con resultado: no se reliquida ni vence.');
        $this->assertSame('vencido', $vencida->estado);
    }
}
