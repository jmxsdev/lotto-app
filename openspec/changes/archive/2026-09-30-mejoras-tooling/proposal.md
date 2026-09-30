# Propuesta: `mejoras-tooling` — ParallelTesting de la suite backend

## Why

La suite corre ~50 min secuencial (`php artisan test`, 1036 tests). El costo dominante son **52 llamadas a `seed(DatabaseSeeder::class)`** (encadena 25 seeders) en el `setUp()` de los tests Feature. El ciclo trabajo (test → fix → re-test) se vuelve inviable. Paralelizar **distribuye** ese costo entre N procesos y acelera el ciclo tanto local como en CI.

## What Changes

- **Dependencia** `brianium/paratest: ^7.20.0` en `require-dev` — única versión compatible con PHP 8.3 + PHPUnit 12.5.31 (v7.21+ exige PHP 8.4 / PHPUnit 13).
- **Script composer** `test:parallel` → `php artisan test --parallel --processes=4`. Usar siempre `--processes=N` (la flag corta `-p` la filtra Collision).
- **CI** (`.github/workflows/ci-cd.yml`): habilitar al usuario MySQL para crear las bases por proceso (`lotto_test_test_1..N`). Elección fina — Opción A (root del service MySQL) vs Opción B (`GRANT` least-privilege) — se define en design. El paso PHPUnit pasa a `--parallel --processes=4 --display-warnings --log-junit junit.xml`.
- **Nota en docs de desarrollo**: requisito de privilegio `CREATE DATABASE` (local y CI) y uso del script.

## Impact

| Área | Impacto | Descripción |
|------|---------|-------------|
| `backend/composer.json` / `composer.lock` | Modificado | dep paratest + script `test:parallel` |
| `.github/workflows/ci-cd.yml` | Modificado | credenciales/privilegios + flag `--parallel` |
| `backend/phpunit.xml` | Posible | solo si se requiere ajustar config de BD |
| `docs/` (desarrollo) | Modificado | nota de privilegios y uso del script |

## Capabilities

### New Capabilities

None (cambio de tooling/CI; sin comportamiento de dominio nuevo).

### Modified Capabilities

None (sin cambio de requisitos a nivel spec).

## Out of Scope

- **Guard en `PagoController::store`**: diferido a la cadena `configuracion-juegos`. El snapshot que lo motiva no existe en main (vive en la cadena) y la sugerencia nació del verify de esa cadena.
- **Hint de `vendible` en el panel**: diferido a la cadena. main no tiene el editor (`juegos.astro` = 35 líneas); la cadena lo reescribe completo (334 líneas).
- Nada de panel/taquilla. El orquestador agregará guard + hint a la cadena durante su sync pre-entrega.

## Risks

| Riesgo | Probabilidad | Mitigación |
|--------|--------------|------------|
| CI sin fix de privilegios → `Access denied` | Alta | Fix obligatorio en `ci-cd.yml` antes de `--parallel` |
| Pin estricto de paratest (`^7.20.0`) | Media | Documentar; no subir a v7.21+ sin subir PHP |
| RAM/IO local con 4 procesos | Media | `--processes` ajustable a mitad de cores |
| Tests no paralelizables (orden/estado compartido) | Baja | Corrida paralela local antes de mergear CI |

## Rollback

Cambio aditivo. Revertir = `composer remove brianium/paratest`, borrar script `test:parallel`, y devolver `ci-cd.yml` al paso secuencial. Sin migraciones ni cambios de datos.

## Success Criteria

- [ ] `composer test:parallel` corre local sin `Access denied`.
- [ ] CI verde con `--parallel --processes=4` y junit sin warnings.
- [ ] Suite paralela sin tests flaky en 2 corridas consecutivas.

## Review Workload Forecast

- `Chained PRs recommended: No` (1 slice, ~25-35 líneas)
- `400-line budget risk: Low`
- `800-line budget risk: Low`
- `Decision needed before apply: No`
