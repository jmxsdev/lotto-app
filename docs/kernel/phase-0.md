# Fase 0 — Red de seguridad e higiene de la suite

**Rama:** `refactor/kernel-identidad`
**Worktree:** `/home/gzuz/Documentos/lotto-app-worktrees/kernel-identidad`
**Objetivo:** congelar el comportamiento actual y dejar la suite rápida y
estricta antes de mover una sola línea del dominio.

**Estado (2026-11-24):** entorno preparado, tareas pendientes. El worktree, las
bases `lotto_kernel` / `lotto_kernel_test` y el índice de CodeGraph ya funcionan.
No se ha escrito ningún contract test ni se ha modificado la suite.

**Modelo de dominio:** B — los seis niveles son entidad + un operador único.
La Fase 0 no depende de esa decisión; la Fase 1 arranca con B.

Esta fase **no cambia comportamiento**. No toca `AlcanceService`, no toca
`UserController`, no toca la base. Solo agrega red de seguridad, acelera el
feedback y prepara el esqueleto de los paquetes.

## 1. Baseline medido (2026-11-26)

| Medición | Valor |
|---|---|
| Archivos de test | 187 |
| Métodos de test | 1.854 |
| Archivos con `RefreshDatabase` | 170 |
| `tests/Unit` | 624 tests, 2.200 assertions, **168,8 s** |
| `GestionUsuariosTest` | 17 tests, **76,7 s** |
| `AlcanceServiceScopeTest` | 13 tests, **84,4 s** |
| `PermissionCatalogTest` | 11 tests, **40,9 s** |
| Suite completa (antes) | **1.854 tests, 1.852 passed, 2 skipped, 11.750 assertions, 1.332 s (22m12s)** |
| Suite estricta (después) | **1.854 tests, 1.854 passed, 0 skipped, 11.753 assertions, 1.332 s (22m12s)** |
| Contract tests | **12 tests, 12 passed, 45 assertions, 38 s** |
| Log del baseline | `/tmp/opencode/phase0-baseline.log` |
| Log de la suite estricta | `/tmp/opencode/phase0-strict.log` |
| Log de contract tests | `/tmp/opencode/phase0-contract.log` |
| Entorno | requiere `log_bin_trust_function_creators=1`; sin eso, los triggers append-only fallan y la suite no es válida |
| Runner | `laravel/pao` v1.1.2 (salida JSON para agentes) |
| `php artisan test --parallel` | **no soportado** (`Unknown option`) |
| `vendor/bin/paratest` | instalado, sin configurar |
| `setTestNow` | 39 usos, 8 resets; ningún archivo queda sin reset o `tearDown` |
| Skips | 0 en la suite estricta; quedan 4 `markTestSkipped` condicionales (1 guard de driver, 3 de datos en `ApuestaServiceTest`) |
| Assert tautológico | corregido: `assertTrue(true)` reemplazado por `assertSame(0, Resultado::count())` |
| Strictness PHPUnit | `failOnWarning` y `failOnRisky` activos; suite completa verde; `beStrictAboutOutput` pendiente |
| Scrapers | usan Guzzle directo (`BaseScraper`); los tests usan fixtures locales |
| Schema dump | bloqueado: el host no tiene `mysqldump` ni `mysql`; opciones: instalar cliente o wrappers de Docker |
| Paquetes | `lotto/kernel` y `lotto/kernel-laravel` instalados por symlink; provider descubierto; boundary test 1/1 |

El primer intento del baseline falló con 107 errores porque `lotto_user` no podía crear los triggers append-only de `audit_events` con binlog activo. Se corrigió con:

```sql
SET GLOBAL log_bin_trust_function_creators = 1;
```

El log del intento fallido quedó en `/tmp/opencode/phase0-baseline-failed.log`.

Conclusión: la suite es amplia y determinista en lo esencial, pero **22 minutos no son un feedback loop rápido** para un refactor. La Fase 0 corrige eso con suite dirigida por slice, schema dump y, si se puede, paralelismo.

## 2. Entregables

### 2.1 Contract tests

Fotografiar el contrato HTTP actual antes de tocar nada:

- Login: éxito, credenciales inválidas, usuario inactivo, sin rol, taquilla con
  dispositivo, panel vs. cliente, cadena inactiva.
- Usuarios: `index`, `show`, `store`, `update`, `destroy`, incluyendo `role` y
  los cuatro vínculos.
- Entidades: banca, grupo, agencia, taquilla (CRUD y cascadas).
- Scope: listados por rol (super_master, master, banca, grupo, agencia, taquilla)
  con dos árboles distintos.
- Errores: códigos, mensajes y estructura JSON.

Reglas de los snapshots:

- Normalizar ids, timestamps, tokens y valores aleatorios.
- Comparar estructura y valores estables, no el orden de claves.
- Un contrato por endpoint; si el endpoint no cambia, el snapshot no cambia.

### 2.2 Higiene y velocidad de la suite

1. **Schema dump**: `php artisan schema:dump` (sin `--prune`, para conservar
   las migraciones) para que `migrate:fresh` use un dump en lugar de 50+
   migraciones. Medir antes/después. Verificar que el dump incluya los triggers
   de `audit_events`; si no, los tests de integridad fallarán.
2. **Paralelismo**: probar `vendor/bin/paratest` con `TEST_TOKEN` y bases por
   proceso. Si no es estable, documentarlo y usar suite dirigida por slice.
3. **Perfil de tests lentos**: `--profile` o equivalente para identificar los 20
   más costosos; adjuntar el reporte.
4. **Auditoría en tests**: evaluar desactivar el recorder en tests que no
   verifican auditoría (trait o flag), midiendo la ganancia. Los tests de
   auditoría lo activan explícitamente.
5. **Estricta**: `failOnWarning`, `failOnRisky`, `beStrictAboutOutput` en
   `phpunit.xml`; corregir los warnings que aparezcan.
6. **Tests débiles**: reemplazar `assertTrue(true)` por una aserción real;
   reemplazar skips condicionales por fixtures.
7. **Tiempo global**: reset de `Carbon::setTestNow()` en
   `Tests\TestCase::tearDown()`.
8. **Red**: `Http::preventStrayRequests()` donde aplique; para el `Client` de
   Guzzle de los scrapers, inyectar un doble o un guard de test.

### 2.3 Esqueleto de los paquetes

Sin código de dominio todavía:

```
backend/packages/kernel/
  composer.json          # name: lotto/kernel, php ^8.3, sin Laravel
  src/                   # carpetas vacías por contexto
  tests/
backend/packages/kernel-laravel/
  composer.json          # name: lotto/kernel-laravel, require lotto/kernel
  src/Providers/KernelLaravelServiceProvider.php
  database/migrations/
```

- Path repositories en `backend/composer.json`.
- Provider con `loadMigrationsFrom` y `publishes(..., 'kernel-migrations')`.
- Test de frontera: `kernel/src` no importa `Illuminate\` ni `App\`.
- Prueba de humo del tag en una app Laravel de scratch (no en `lotto-app`):
  `php artisan vendor:publish --tag=kernel-migrations`.

### 2.4 Política de suites

| Cuándo | Qué se corre |
|---|---|
| Cada PR/slice | Archivos tocados + tests del kernel + boundary test |
| Cada fase | Suite completa |
| CI | Suite completa (con schema dump/paralelo si se valida) |

Objetivo: **suite dirigida < 60 s** para identidad/scope; suite completa
documentada y con objetivo < 15 min (ideal < 5 min).

## 3. Tareas

- [x] Medir y registrar el baseline real: 1.854 tests, 1.852 passed, 2 skipped, 22m12s.
- [ ] Crear `database/schema/mysql-schema.sql` y medir la mejora. **Bloqueado**: el host no tiene `mysqldump` ni `mysql`.
- [ ] Probar `paratest` y documentar el resultado.
- [x] Activar `failOnWarning` y `failOnRisky` en `phpunit.xml`. `beStrictAboutOutput` queda pendiente.
- [ ] Activar `beStrictAboutOutputDuringTests` y corregir la salida de tests.
- [x] Reparar `assertTrue(true)` y los 2 skips del baseline (`PluginIntegrationTest`, `ScrapeResultsJobTest`); ambos pasan con strictness.
- [ ] Revisar los 4 `markTestSkipped` restantes (1 guard de driver, 3 condicionales en `ApuestaServiceTest`).
- [ ] Reset global de Carbon en `TestCase::tearDown()`.
- [ ] Guard de red en tests.
- [x] Correr la suite completa con strictness: 1.854 tests, 1.854 passed, 0 skips, 22m12s.
- [x] Contract tests creados y ejecutados: 12 tests, 12 passed, 45 assertions, 38 s.
- [x] Crear el esqueleto de `lotto/kernel` y `lotto/kernel-laravel`; `composer update` y boundary test verdes.
- [ ] Verificar el tag `kernel-migrations` en una app scratch (la carpeta de migraciones aún está vacía; se valida en la fase 3b).
- [x] Documentar la política de suites por PR/fase/CI.

## 4. Criterios de aceptación

1. Suite completa verde, sin cambios de comportamiento.
2. Duración completa medida y documentada; objetivo < 15 min.
3. Cero warnings con `failOnWarning`.
4. Cero `assertTrue(true)` y cero skips condicionales por datos.
5. Contract tests byte-level para los endpoints seleccionados.
6. `kernel/src` sin imports de Laravel (boundary test verde).
7. `composer dump-autoload` y el arranque de la app funcionan con los paquetes.
8. `vendor:publish --tag=kernel-migrations` funciona en una app scratch.
9. Suite dirigida < 60 s para el slice de identidad/scope.

## 5. Comandos de referencia

```bash
# baseline por archivo
php artisan test tests/Feature/GestionUsuariosTest.php
php artisan test tests/Feature/AlcanceServiceScopeTest.php

# baseline completo (ejecutar solo; comparte lotto_kernel_test)
start=$(date +%s)
vendor/bin/phpunit --debug > /tmp/opencode/phase0-baseline.log 2>&1
echo "ELAPSED:$(( $(date +%s) - start ))s" | tee -a /tmp/opencode/phase0-baseline.log

# schema dump (sin --prune; usa lotto_kernel, no la base de tests)
php artisan schema:dump

# experimento de paralelismo
TEST_TOKEN=1 vendor/bin/paratest --processes=4 tests/Unit

# suite dirigida
php artisan test \
  tests/Feature/PermissionCatalogTest.php \
  tests/Feature/RoutePermissionCoverageTest.php \
  tests/Feature/GestionUsuariosTest.php

# paquetes
composer dump-autoload
php artisan vendor:publish --tag=kernel-migrations   # solo en app scratch
```

## 6. Fuera de alcance

- Mover vocabulario, cadena o scope (Fase 1–3).
- Cambios de comportamiento B1–B7 (Fase 5).
- El principal `system_admin` y los roles de BD (Fase 5e/6).
- Comisiones, reparto o `docs/dev/diseno-reparto-participaciones.md`.
- Cambios de UI del panel o de la taquilla.
