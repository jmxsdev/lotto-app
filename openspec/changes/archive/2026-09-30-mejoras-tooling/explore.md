# Exploración — `mejoras-tooling`

**Fecha**: 2026-09-29 · **Fase**: explore (solo lectura) · **Base**: main `c375786`
**Origen**: SUGGESTIONS del verify-report de `configuracion-juegos` (archivado en `openspec/changes/archive/2026-09-28-configuracion-juegos/verify-report.md`, rama `feat/configuracion-juegos-s4`, pendiente de merge).

Tres sugerencias del verify:
1. **Guard en `PagoController::store`** con `detalles->first()` (snapshot del primer detalle).
2. **Tiempo de suite** (~49,6 min local) → ParallelTesting.
3. **Hint de `vendible`** en el panel.

---

## 1. ParallelTesting (`brianium/paratest`) — núcleo del ciclo

### 1.1 Compatibilidad con el stack actual (evidencia)

| Componente | Versión | Fuente |
|---|---|---|
| PHP | `^8.3` | `backend/composer.json` L9 |
| laravel/framework | `^13.8` (lock `v13.19.0`) | `composer.json` L11 + `composer.lock` |
| phpunit/phpunit | `^12.5.12` (lock `12.5.31`) | `composer.json` L26 + `composer.lock` |
| nunomaduro/collision | `^8.6` (provee `php artisan test`) | `composer.json` L25 |

- `laravel/framework v13.19.0` **sugiere** `brianium/paratest: ^7.0 || ^8.0` (campo `suggest` en `composer.lock`).
- Collision 8.x: `--parallel` exige "at least ParaTest 7.x" (`vendor/nunomaduro/collision/src/Adapters/Laravel/Commands/TestCommand.php` L91-93).
- **Versión de paratest compatible** (packagist `brianium/paratest`): solo **v7.20.0** declara `phpunit ^12.5.14 || ^13.0.5` y `php ~8.3.0 || ~8.4.0 || ~8.5.0`. v7.21+ exige `php ~8.4.0` y `phpunit ^13` → **incompatibles** con este repo. Pin requerido: **`brianium/paratest: ^7.20.0`** en `require-dev` (coincide con el dev-dep que usa el propio laravel/framework).
- Extensiones `pcntl`/`posix` (necesarias para paratest): **ya presentes en CI** (`ci-cd.yml` L46: `extensions: pdo_mysql, bcmath, zip, redis, pcntl, posix`).

**Veredicto**: paratest ES viable con `^7.20.0`; es la versión exacta que soporta PHP 8.3 + PHPUnit 12.5.31.

### 1.2 Mecanismo de bases por proceso (evidencia del source de Laravel 13)

- `vendor/laravel/framework/src/Illuminate/Testing/Concerns/TestDatabases.php`:
  - `testDatabase()` → `"{$database}_test_{$token}"` → con `DB_DATABASE=lotto_test` crea **`lotto_test_test_1`, `lotto_test_test_2`, …** (nótese el doble `_test_`).
  - `ensureTestDatabaseExists()` → si la BD del token no existe, ejecuta `Schema::createDatabase()` **con la conexión base** (usuario de `lotto_test`).
  - `--recreate-databases` / `--drop-databases` usan `Schema::dropDatabaseIfExists()` (privilegio DROP).
- **Requisito**: el usuario MySQL necesita privilegio **`CREATE DATABASE`** (y DROP si se usan esas flags).
- Conteo de procesos: `php artisan test --parallel --processes=N` (collision toma `array_slice($_SERVER['argv'], 2)`, `TestCommand.php` L96-98). ⚠️ **La flag corta `-p N` se filtra** (`Str::startsWith($option, '-p')`, `TestCommand.php` L265) → usar **siempre `--processes=N`**.

### 1.3 MySQL local vs CI (privilegios)

- **Local**: `phpunit.xml` usa `force="false"` → toma `DB_*` del `.env` local. Si el usuario local es root o tiene privilegios globales, `--parallel` funciona directo. Si el usuario no puede `CREATE DATABASE`, falla con `Access denied` → documentar en README/docs.
- **CI** (`.github/workflows/ci-cd.yml`): service `mysql:8.0` con `MYSQL_USER=lotto_test`, `MYSQL_DATABASE=lotto_test`. La imagen oficial de MySQL otorga **solo** `GRANT ALL ON \`lotto_test\`.*` → el usuario **NO puede crear `lotto_test_test_N`** → el paso PHPUnit con `--parallel` fallaría tal cual está.
  - **Opción A (recomendada, mínima)**: usar root para el paso de tests — en el `env:` del job cambiar `DB_USERNAME: root`, `DB_PASSWORD: root` (`MYSQL_ROOT_PASSWORD: root` ya está definido en el service). Son 2 líneas; root en el contenedor efímero de CI es práctica común y cubre CREATE/DROP.
  - **Opción B (least-privilege)**: mantener `lotto_test` y añadir un paso que conceda el patrón: `docker exec ${{ job.services.mysql.id }} mysql -uroot -proot -e "GRANT ALL PRIVILEGES ON \`lotto_test\_%\`.* TO 'lotto_test'@'%'; FLUSH PRIVILEGES;"`. Más pasos y escaping de backticks; no aporta valor real en CI efímero.
  - **Opción C (descartada)**: `--without-databases` — desactiva el aislamiento por proceso y rompe la semántica de RefreshDatabase en paralelo.

### 1.4 Riesgos de tests no paralelizables (evidencia sobre el código actual)

- **Costo dominante**: **52 llamadas a `seed(DatabaseSeeder::class)`** (`grep -rn "seed(DatabaseSeeder::class)" backend/tests` = 52) en `setUp()` de tests Feature. `DatabaseSeeder` encadena **25 seeders** (~2.570 líneas de seeders). Con 1.071 métodos de test (`grep -rc "function test_"`) y 104 de 109 archivos usando `RefreshDatabase`, el costo por test ≈ insertar la base completa dentro de la transacción. El paralelismo **distribuye** este costo entre procesos; no lo elimina.
- **Migraciones**: `migrate:fresh` corre 1 vez por proceso (RefreshDatabaseState por proceso) → con 4 procesos = 4 migraciones simultáneas; aceptable en CI y local.
- **Archivos temporales** (revisados): `ReleasePublishCommandTest` escribe `sys_get_temp_dir()/Taquilla-Setup-1.0.0.exe` (nombre fijo pero único por archivo → paratest asigna 1 archivo por proceso → seguro); `ResultadosMetricasCommandTest` usa `storage_path(.../metricas-'.uniqid())` → seguro; `DownloadTest`/`ReleasePublishCommandTest` usan `Storage::fake('releases')` (por proceso) → seguro; `JuegosJsonTest` exporta con `--path=<tmp>` y verifica `docs/juegos.json` intacto por hash → seguro.
- **Red**: sin llamadas HTTP reales — los scrapers se prueban con fixtures locales (`tests/Fixtures/`, 43 archivos; ej. `CazalotonScraperTest` parsea `loteriadehoy_cazaloton.html`); `Http::` solo aparece como fake/assert.
- **Tiempo/estado global**: `sleep(1)` en `Unit/ActivacionTest.php` L85; `Carbon::set`/`setTestNow` en `CierreCajaTest`, `ReconciliarSorteosCommandTest`, `ResultadosMetricasCommandTest` — estáticos **por proceso**, seguros.
- **Skipped condicionales** (preexistentes): `PluginIntegrationTest`, `ScrapeResultsJobTest`, `ApuestaServiceTest` (3 casos) — sin impacto en paralelo.
- **Orden/estado entre archivos**: no se detectaron dependencias; el aislamiento por proceso (BD propia) es equivalente al secuencial (que comparte `lotto_test` con rollback por test).

### 1.5 Tests más lentos (identificados por estructura)

Los Feature que seedan `DatabaseSeeder` en `setUp` con más métodos (top):
`CierreCajaTest` (48) · `MotorPremiosRegresionTest` (30) · `LimitesScopedApiTest` (30) · `AgenciaScopeTest` (23) · `ModalidadesVentaTest` (21) · `ModalidadesSingleDrawTest` (21) · `ApuestaTest` (19) · `ActivacionEntidadesTest` (19).
Confirmación empírica disponible con `php artisan test --profile` (flag soportada por collision; opcional en apply).

### 1.6 Recomendación

- **Ámbito**: **local Y CI** (ambos se benefician; el ciclo de desarrollo local es el que más sufre con ~50 min).
- **Procesos**: `--processes=4` en CI (ubuntu-latest = 4 vCPU). Local: `--processes=4` por defecto (ajustable a mitad de cores).
- **CI**: Opción A (root) + `php artisan test --parallel --processes=4 --display-warnings --log-junit junit.xml` (+ `--recreate-databases` opcional para estado determinista). Mantener `timeout-minutes: 60`.
- **Local**: script composer `"test:parallel": ["@php artisan config:clear --ansi @no_additional_args", "@php artisan test --parallel --processes=4"]` (decisión final en design/tasks).
- Los runs siguientes de `--parallel` local reutilizan las BD creadas (más rápido); `--recreate-databases` solo si cambian migraciones.

---

## 2. Guard en `PagoController` (SUGGESTION 1 del verify)

**Sugerencia exacta** (verify-report): *"PagoController::store con `detalles->first()`: usa el snapshot del PRIMER detalle; si una apuesta tuviera varios detalles con snapshots distintos, solo se considera uno. Hoy `createApuesta` crea exactamente un detalle por apuesta (invariante vigente), pero un comentario/guard explícito lo haría más robusto ante cambios futuros."*

**Estado en main** (`backend/app/Http/Controllers/Api/PagoController.php`):
- L154: `$detalle = $apuesta->detalles()->first();` (actualiza `premio_ganado`/`premio_ganado_usd`). **El snapshot NO existe en main** (la migración `premios_snapshot` y su uso son de la cadena).

**Estado en la cadena** (`git show feat/configuracion-juegos-s4:.../PagoController.php`):
- Añade eager-load `detalles` (L58) y `$premiosSnapshot = $apuesta->detalles->first()?->premios_snapshot;` en `calcularPremio()` (L241) — **el snapshot vive en la cadena**.
- El diff main→s4 toca 2 hunks (eager-load + `calcularPremio`); la L154 **no está en el diff** → un comentario en main no colisionaría textualmente con la cadena.

**Opciones**:
- **A — Diferir a la cadena (recomendada)**. Razones: (1) el invariante que protege el guard ("1 detalle por apuesta", snapshot por detalle) lo **introduce la cadena**; (2) la sugerencia nació del verify de la cadena → dueño natural; (3) en main un comentario sobre "snapshot" no tendría sentido (no existe ese concepto); (4) tocar main añade fricción al merge pendiente de un archivo que la cadena modifica. Costo: cero — es sugerencia de calidad, no bug; el invariante se cumple hoy.
- **B — Fix mínimo merge-friendly en main**: comentario-only en L154 documentando el invariante (sin mencionar snapshot). ~3 líneas, sin conflicto textual con la cadena. Pero la cadena debería igualmente añadir el guard del snapshot en L241 antes de mergear; hacerlo en main duplicaría el trabajo.

**Recomendación**: **diferir (Opción A)**. Si el orchestrator quiere cerrarlo YA, Opción B como comentario-only y coordinar con la cadena.

---

## 3. Hint de `vendible` (SUGGESTION 3 del verify)

**Sugerencia exacta**: *"el panel lo muestra en el editor como lectura; el resto del panel usa `active`. Coherente con D5, pero un tooltip/hint que explique «vendible = activo» en la tabla general ahorraría confusión de operadores."*

**Estado real**:
- main: `panel/src/pages/juegos.astro` = **35 líneas** — tabla (Nombre, Slug, Tipo, Multiplicador, Scraper, Estado) + badge toggle. **No hay editor de premios NI columna `vendible`** (`git show main:panel/src/pages/juegos.astro | wc -l` = 35).
- cadena: `juegos.astro` reescrito a **334 líneas** con editor modal que muestra "Vendible: Sí (activo) / No (inactivo)" (L45, L252, L265).
- El backend de `vendible = active` **ya está en main** (`backend/app/Services/JuegoCatalogoService.php` L43) y probado (`JuegosJsonTest` L119-124, 157, 174) — el hint es puramente UX del editor de la cadena.

**Recomendación**: **diferir a la cadena**. En main no existe la UI destino (editor); aplicar el hint en main tocaría un archivo que la cadena **reescribe completo** → conflicto de merge garantizado y trabajo perdido. El hint debe aplicarse sobre el `juegos.astro` de la cadena (o como follow-up tras su merge).

---

## Riesgos

1. **CI sin fix de privilegios** → la suite paralela falla con `Access denied` (`lotto_test` no puede `CREATE DATABASE lotto_test_test_N`). Fix mínimo: root en el paso tests (Opción A).
2. **Pin de paratest**: `^7.20.0`. v7.21+ exige PHP 8.4 / PHPUnit 13 — composer lo resolvería bien con PHP 8.3 (excluye v7.21+), pero documentar para no "upgradear" a ciegas.
3. **Usuarios MySQL locales sin privilegio CREATE** → `--parallel` falla local; incluir nota en docs.
4. **Seeders pesados**: el speedup es por distribución, no por reducción de trabajo: 52× `DatabaseSeeder` por suite se mantiene; el costo por proceso ≈ (migrate + seeds)/N.
5. **`-p N` se filtra en collision** → usar siempre `--processes=N`.
6. **junit + paratest**: `--log-junit` es soportado por paratest (agregación); validar que el paso de grep de warnings del workflow siga funcionando.
7. **Flakiness latente**: si algún test dependiera de orden/estado compartido entre archivos (no detectado), el paralelo lo expondría — validar con una corrida paralela local antes de mergear CI.

## Estimación de slices

- **1 slice principal** (muy por debajo de 400 líneas de revisión): `composer.json` (+1 línea paratest), `ci-cd.yml` (~4-8 líneas: root + `--parallel --processes=4`), script `test:parallel` en composer (+2), nota en docs (~10). **Total ≈ 20-35 líneas autorales**.
- **Slice 2 opcional** (solo si NO se difiere el guard): comentario en `PagoController.php` (~3 líneas) — innecesario con la recomendación de diferir.
- El hint de `vendible` y el guard del snapshot quedan **en la cadena** (0 líneas en este cambio).
- Verificación del cambio: corrida paralela local + CI verde (no aplica unit test nuevo; es tooling).

**Recomendación de alcance**: 1 slice, sin slice 2.