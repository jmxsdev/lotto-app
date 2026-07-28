# Sistema de Plugins y Scrapers

## 1. Sistema de Plugins (JuegoInterface)

### Contrato

Cada juego implementa `App\Plugins\Contracts\JuegoInterface`:

```php
interface JuegoInterface
{
    public function validarApuesta(array $data): bool;
    public function calcularPremio(array $apuesta, array $resultados): array;
    public function obtenerReglas(): array;
    public function obtenerOpciones(): array;
    public function obtenerMultiplicador(): float;
    public function getValidationRules(): array;
    public function getValidationMessages(): array;
}
```

### Auto-discovery

`PluginServiceProvider` escanea `app/Plugins/Juegos/` y carga automáticamente toda clase que implemente `JuegoInterface`. Se registra como singleton `'plugins'` en el contenedor.

### Base de datos

La tabla `plugin_juegos` mapea `juego_id` → `class_namespace` del plugin. Cada plugin tiene `version` y `active`.

---

### Paso a paso: Agregar un nuevo juego

#### 1. Crear la clase plugin

`app/Plugins/Juegos/MiJuego.php`:

```php
<?php

namespace App\Plugins\Juegos;

use App\Plugins\Contracts\JuegoInterface;
use Illuminate\Validation\Rule;

class MiJuego implements JuegoInterface
{
    protected string $multiplicador = '30';

    public function validarApuesta(array $data): bool
    {
        // Validar que la combinación sea válida
        return true;
    }

    public function calcularPremio(array $apuesta, array $resultados): array
    {
        // Retornar ['premio_bs' => float, 'premio_usd' => float]
        $amountBs = $apuesta['amount_bs'] ?? 0;
        $amountUsd = $apuesta['amount_usd'] ?? 0;
        return [
            'premio_bs' => $amountBs * (float) $this->multiplicador,
            'premio_usd' => $amountUsd * (float) $this->multiplicador,
        ];
    }

    public function obtenerReglas(): array
    {
        return [
            'descripcion' => 'Descripción del juego',
            'tipo' => 'numero',
            'multiplicador' => (float) $this->multiplicador,
        ];
    }

    public function obtenerOpciones(): array
    {
        return [
            ['label' => 'Opción 1', 'value' => 'op1'],
        ];
    }

    public function obtenerMultiplicador(): float
    {
        return (float) $this->multiplicador;
    }

    public function getValidationRules(): array
    {
        return [
            'combinacion' => 'required|array|min:1',
            'combinacion.numero' => 'required|string|size:3',
        ];
    }

    public function getValidationMessages(): array
    {
        return [
            'combinacion.numero.size' => 'El número debe tener exactamente 3 dígitos.',
        ];
    }
}
```

#### 2. Crear el seeder

`database/seeders/MiJuegoSeeder.php`:

```php
<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Juego;
use App\Models\JuegoOpcion;
use App\Models\JuegoHorario;
use App\Models\PluginJuego;
use App\Plugins\Juegos\MiJuego;

class MiJuegoSeeder extends Seeder
{
    public function run(): void
    {
        $juego = Juego::firstOrCreate(
            ['slug' => 'mi-juego'],
            [
                'name' => 'Mi Juego',
                'type' => 'mi_juego',
                'config' => json_encode(['premio_multiplo' => 30]),
                'costo_minimo' => 3600,
                'requires_scraper' => false,        // true si tiene scraper
                'scraper_url' => null,              // URL fuente si aplica
                'active' => true,
            ]
        );

        PluginJuego::firstOrCreate(
            ['juego_id' => $juego->id],
            [
                'class_namespace' => MiJuego::class,
                'version' => '1.0.0',
                'active' => true,
            ]
        );

        // Opciones (ej: animales, signos, números especiales)
        foreach (['op1', 'op2'] as $i => $value) {
            JuegoOpcion::firstOrCreate(
                ['juego_id' => $juego->id, 'value' => $value],
                [
                    'label' => ucfirst($value),
                    'numero' => null,
                    'sort_order' => $i,
                    'active' => true,
                ]
            );
        }

        // Horarios de sorteo
        foreach (['12:00', '18:00'] as $hora) {
            JuegoHorario::firstOrCreate(
                ['juego_id' => $juego->id, 'hora' => $hora],
                ['active' => true]
            );
        }
    }
}
```

#### 3. Registrar en DatabaseSeeder

```php
$this->call([
    // ...
    MiJuegoSeeder::class,
]);
```

#### 4. Ejecutar

```bash
php artisan db:seed --class=MiJuegoSeeder
```

---

## 2. Sistema de Scrapers

### BaseScraper

Clase abstracta en `App\Plugins\Scrapers\BaseScraper`:

- Cliente HTTP (Guzzle) con headers de navegador, timeout 30s
- **Template method**: `execute(string $fecha = null): array`
  1. `fetch(string $fecha): string` — obtener HTML/JSON (abstracto)
  2. `parse(string $rawData): array` — parsear a array de resultados (abstracto)
  3. Opcional: `saveResults(array $resultados, string $fecha): int` — upsert en DB
- Helpers: `getHtml()`, `postJson()`, `createCrawler()` (DOM Crawler)
- Logging: `logInfo()`, `logError()`, `logWarning()`

### Ejemplo vivo: AnimalitosScraper

`app/Plugins/Scrapers/AnimalitosScraper.php`:

1. **fetch($fecha)**: Obtiene HTML de `lottoactivo.com/resultados/animalitos/{fecha}/`, extrae token CSRF de un script vía regex, luego POSTea al API interno `/core/process.php` con el token, lotería y fecha.
2. **parse($rawData)**: Decodifica JSON, itera `datos[].resultados[]`, mapea a estructura `Resultado` usando `mapToResultado()`. Si no existe el juego en DB lo crea con `findOrCreateJuego()`.
3. **saveResults()**: Upsert por `juego_id + fecha_sorteo + hora_sorteo`.

```php
class AnimalitosScraper extends BaseScraper
{
    protected string $baseUrl = 'https://www.lottoactivo.com';
    protected string $scraperName = 'AnimalitosScraper';

    public function fetch(string $fecha): string { /* ... */ }
    public function parse(string $rawData): array { /* ... */ }
    public function saveResults(array $resultados, string $fecha): int { /* ... */ }
}
```

### FetchResultsJob

`app/Jobs/FetchResultsJob.php` — Job queueable con 3 reintentos y backoff de 60s:

```php
$scraper = new AnimalitosScraper();
$resultados = $scraper->execute($fecha);
$guardados = $scraper->saveResults($resultados, $fecha);
```

### Scheduler

`routes/console.php`:

```php
Schedule::job(new FetchResultsJob)->everyFiveMinutes();
```

---

### Paso a paso: Crear un scraper para un juego

#### 1. Crear la clase scraper

`app/Plugins/Scrapers/MiJuegoScraper.php`:

```php
<?php

namespace App\Plugins\Scrapers;

use App\Models\Juego;
use App\Models\Resultado;

class MiJuegoScraper extends BaseScraper
{
    protected string $baseUrl = 'https://fuente-oficial.com';
    protected string $scraperName = 'MiJuegoScraper';

    public function fetch(string $fecha): string
    {
        // Obtener datos crudos (HTML, JSON, XML) desde la fuente
        return $this->getHtml($this->baseUrl . '/resultados/' . $fecha);
    }

    public function parse(string $rawData): array
    {
        // Transformar datos crudos a array de resultados
        $resultados = [];

        // Buscar o crear el juego en DB
        $juego = Juego::firstOrCreate(
            ['slug' => 'mi-juego'],
            ['name' => 'Mi Juego', /* ... */]
        );

        // Parsear y mapear cada resultado
        foreach ($parsed as $item) {
            $resultados[] = [
                'juego_id' => $juego->id,
                'fecha_sorteo' => now()->format('Y-m-d'),
                'hora_sorteo' => $item['hora'],
                'numeros_ganadores' => json_encode($item['numeros']),
                // ... campos adicionales según el juego
            ];
        }

        return $resultados;
    }

    public function saveResults(array $resultados, string $fecha): int
    {
        $guardados = 0;
        foreach ($resultados as $data) {
            $data['fecha_sorteo'] = $fecha;

            Resultado::updateOrCreate(
                [
                    'juego_id' => $data['juego_id'],
                    'fecha_sorteo' => $fecha,
                    'hora_sorteo' => $data['hora_sorteo'],
                ],
                $data
            );
            $guardados++;
        }
        return $guardados;
    }
}
```

#### 2. Crear el Job

`app/Jobs/FetchMiJuegoResultsJob.php`:

```php
<?php

namespace App\Jobs;

use App\Models\Log;
use App\Plugins\Scrapers\MiJuegoScraper;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class FetchMiJuegoResultsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $backoff = 60;

    public function __construct(public ?string $fecha = null) {}

    public function handle(): void
    {
        $fecha = $this->fecha ?? now()->format('Y-m-d');

        try {
            $scraper = new MiJuegoScraper();
            $resultados = $scraper->execute($fecha);

            if (empty($resultados)) {
                \Log::warning("Sin resultados para {$fecha}");
                return;
            }

            $guardados = $scraper->saveResults($resultados, $fecha);
            \Log::info("Resultados guardados: {$guardados}");

        } catch (\Exception $e) {
            \Log::error("Error: " . $e->getMessage());
            throw $e;
        }
    }
}
```

#### 3. Registrar en el scheduler

`routes/console.php`:

```php
Schedule::job(new FetchMiJuegoResultsJob)->everyFiveMinutes();
```

#### 4. Actualizar el seeder del juego

Cambiar `requires_scraper => true` y `scraper_url => 'https://fuente-oficial.com/'`.

---

## 3. Testing

### Tests unitarios (parse)

`tests/Unit/AnimalitosScraperTest.php` — usa fixtures JSON/HTML, prueba `extractToken()`, `parse()` con datos válidos, vacíos e inválidos. Usa Reflection para métodos privados.

```php
$jsonResponse = file_get_contents(base_path('tests/Fixtures/animalitos_response.json'));
$resultados = $scraper->parse($jsonResponse);
$this->assertCount(6, $resultados);
$this->assertEquals('Delfin', $resultados[0]['nombre_animal']);
```

### Tests de integración (saveResults + DB)

`tests/Feature/FetchResultsJobTest.php` — usa `DatabaseTransactions`, corre seeders, llama `parse()` + `saveResults()`, verifica:
- Conteo correcto en DB
- Upsert no duplica
- Respuesta vacía no guarda nada

### Fixtures

`tests/Fixtures/` — JSON/HTML representativos de la respuesta real del scraper, usados como mock para tests sin HTTP.

---

## 4. Pendiente: Scraper Triple Zulia

Actualmente no tiene scraper (`requires_scraper = false`, `scraper_url = null`). Para implementarlo:

1. Investigar dónde publica resultados Triple Zulia (fuente oficial)
2. Crear `TripleZuliaScraper extends BaseScraper`
3. Crear `FetchTripleZuliaResultsJob`
4. Actualizar `TripleZuliaSeeder` con `requires_scraper = true` y `scraper_url`
5. Agregar al scheduler en `routes/console.php`
6. Crear fixtures y tests

Formato de `numeros_ganadores` que espera el plugin TripleZulia:

```json
{
    "triple_a": "123",
    "triple_b": "456",
    "triple_c": "789",
    "signo": "LEO"
}
```

Campos extras del resultado: `nombre_animal`, `imagen_animal`, `color_animal` se dejan en `null` (no aplican para Triple Zulia).
