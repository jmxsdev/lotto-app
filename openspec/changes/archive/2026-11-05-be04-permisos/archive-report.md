# Archive Report — BE-04: `be04-permisos`

**Change**: `be04-permisos` (BE-04) — Catálogo de permisos por capability y migración `role:` → `permission:`
**Archivado a**: `openspec/changes/archive/2026-11-05-be04-permisos/`
**Fecha de archivo**: 2026-11-05
**Estado final**: **PASS WITH WARNINGS** — 15/15 requirements, 20/20 scenarios, 0 CRITICAL, 0 blockers.
**Rama de trabajo**: `be04/wu4-admin-reportes` · **HEAD al archivar**: `81c4b8f` · **Base del change**: `a229c1d`

## 1. Estado final y reconciliación de fuentes

`verify-report` (#782) se escribió sobre `f2deeec` y dejó abiertos W2 y W3. El commit posterior `81c4b8f` los corrigió **antes** de este archivo:

| Warning de `verify-report` | Estado al cierre | Evidencia |
|---|---|---|
| W2 — `POST /apuestas` listaba `agencia` en docs/design | **Corregido** | `81c4b8f` cambia `docs/dev/permisos-capabilities.md` §7 y `design.md` §Mapeo a “master, banca, grupo, taquilla (agencia 403)” |
| W3 — bookkeeping (`tasks.md` criterio global `[ ]`, `pendientes-front.md` “en vuelo”) | **Corregido** | `81c4b8f` marca `[x]` el criterio global y pone BE-04 en `cerrado` |
| W1 — seeder idempotente sin test de doble ejecución | **Abierto (warning)** | Sin cambios posteriores; ver §6 |
| W4 — `route:list` no ejecutado | **Abierto (limitación)** | 0 `role:` verificado estáticamente; ver §6 |

No hay CRITICAL ni contradicciones no rankeables. El `tasks.md` archivado no tiene tareas de implementación sin marcar: **22/22 tareas de WU + 7/7 criterios globales**. La observación Engram `sdd/be04-permisos/tasks` (#780) es un snapshot de planificación (revisión 1) y quedó superada por el `tasks.md` final; se conserva como historial.

## 2. Alcance entregado

- **Catálogo de 34 permisos** con guard `api`, preservando nombres existentes y agregando los 11 faltantes.
- **Matriz capability × rol** como fuente única del seeder: master **33**, banca **24**, grupo **21**, agencia **17**, taquilla **12**, super_master **0**.
- **`manage_users` y `manage_apuestas` retirados**; `manage_comisiones` conservado sin rol (solo bypass).
- **`Gate::before` acotado** en `AppServiceProvider` para `super_master`: `true` solo si la ability existe como permiso guard `api`; `null` en cualquier otro caso (nunca `false`), sin depender de filas.
- **Migración total `role:` → `permission:`** en `routes/api.php` y `routes/web.php`; alias `role` eliminado de `bootstrap/app.php`; splits por método en los `apiResource` mixtos.
- **Rutas antes sin permiso cubiertas**: `GET /juegos*` → `view_juegos`; `GET /resultados*` → `view_resultados`.
- **Taquilla**: conserva reportes propios (`view_reports`) y gana releases (`download_release`, cambio deliberado).
- **Agencia**: mantiene su edición de ficha (`manage_agencias`) y sigue sin tickets ni pagos.
- **Row scoping (#85) intacto**: `AlcanceService` y policies no se modificaron.
- **Documentación**: `docs/dev/permisos-capabilities.md` (catálogo, matriz, mapeo, operación del seeder) y fila BE-04 cerrada en `pendientes-front.md`.

## 3. Commits del ciclo

| WU | Implementación | Docs/registro |
|---|---|---|
| Planificación | — | `1441441` |
| WU-1 — catálogo, `Gate::before`, doc, spec | `a0fe3f5` | `afc0acf` |
| WU-2 — usuarios y entidades | `dbdf97a` | `39c52eb` |
| WU-3 — operación (juegos, tickets, pagos, cierres, límites) | `cd453bd` | `580625f` |
| WU-4 — admin, reportes, releases | `e34a526` | `2d6aa84` |
| Fix de test (`/pagos/{id}`) | `f2deeec` | — |
| Cierre docs + corrección W2/W3 | — | `81c4b8f` |

## 4. Evidencia de verificación

Evidencia **aportada por el usuario** (regla dura del ciclo: el agente no ejecuta tests, migraciones ni artisan):

| Evidencia | Resultado |
|---|---|
| Suite completa `php artisan test --parallel --processes=4` | **1501 tests, 8678 assertions, 2 skipped, OK** (exit 0) |
| Formato `./vendor/bin/pint --test` | **passed** (exit 0) |
| Filtro WU-1 `PermissionCatalogTest` | 10 passed |
| Filtro WU-2 entidades/usuarios/config | 63 passed |
| Filtro WU-3 operación/tickets | 113 passed |
| Filtro WU-4 admin/reportes/releases | 57 passed (tras `f2deeec`) |

`verify-report` fija `verdict: pass_with_warnings`, `requirements: 15/15`, `scenarios: 20/20`, `critical_findings: 0`, `blockers: 0`.

## 5. Specs sincronizadas

| Domain | Acción | Detalle |
|---|---|---|
| `catalogo-permisos` | **Creado** | Copia mecánica del delta (spec completo): 7 requirements / 10 escenarios |
| `autorizacion-capability` | **Creado** | Copia mecánica del delta (spec completo): 8 requirements / 10 escenarios |

No existían main specs previos para estos dominios, por lo que no hubo merge destructivo ni requirements duplicados. El delta es el spec completo.

Lectura de verificación (debe ser vacía):

```text
$ diff -r openspec/changes/be04-permisos/specs/catalogo-permisos openspec/specs/catalogo-permisos
(exit 0, sin diferencias)

$ diff -r openspec/changes/be04-permisos/specs/autorizacion-capability openspec/specs/autorizacion-capability
(exit 0, sin diferencias)
```

## 6. Warnings finales

- **W1 — Seeder idempotente sin test de doble ejecución (abierto).** `PermissionCatalogTest` siembra una sola vez por test. La implementación es estructuralmente idempotente (`firstOrCreate`, `syncPermissions`, borrado de retirados con FK `cascadeOnDelete`, `forgetCachedPermissions()` al inicio y al final), pero ningún test re-ejecuta el seeder sobre una BD ya sembrada. Deuda de evidencia, no de runtime.
- **W2 — Documentación de `POST /apuestas` (cerrado).** Corregido en `81c4b8f`: `docs/dev/permisos-capabilities.md` y `design.md` ahora excluyen a `agencia` (403 por matriz).
- **W3 — Bookkeeping (cerrado).** Corregido en `81c4b8f`: criterio global de suite/Pint marcado `[x]` y fila BE-04 de `pendientes-front.md` en `cerrado`.
- **W4 — `route:list` no ejecutado (limitación aceptada).** La regla del usuario impidió ejecutar artisan. El 0 `role:` se verificó estáticamente (grep = 0 en `routes/api.php`/`routes/web.php`) y con `RoutePermissionCoverageTest`; el riesgo se considera bajo. Se recomienda correrlo en el deploy real.

## 7. Runbook de deploy

> **Orden crítico**: el catálogo se siembra **antes** de enrutar tráfico a las rutas migradas. Si se invierte, las rutas responden 403 masivos (fail-closed, nunca 500) hasta refrescar la caché.

1. **Backup** de `role_has_permissions` (y, por seguridad, `permissions`, `roles`, `model_has_permissions`) antes de tocar nada.
2. Desplegar la imagen que contiene el seeder/catálogo de BE-04, **sin** cambiar todavía el tráfico a las rutas nuevas (contenedor one-off o ventana de mantenimiento).
3. Ejecutar el seeder:
   ```bash
   php artisan db:seed --class=RolesAndPermissionsSeeder --force
   ```
4. Refrescar la caché de Spatie:
   ```bash
   php artisan permission:cache-reset
   ```
   (o `php artisan optimize:clear`; el seeder además llama `forgetCachedPermissions()` al inicio y al final).
5. **Verificar el estado sembrado**:
   ```sql
   SELECT COUNT(*) FROM permissions WHERE guard_name = 'api';              -- 34
   SELECT COUNT(*) FROM permissions
    WHERE name IN ('manage_users', 'manage_apuestas');                     -- 0
   ```
   Conteos por rol esperados: master **33**, banca **24**, grupo **21**, agencia **17**, taquilla **12**, super_master **0**.
6. **Smoke tests** (antes de abrir el tráfico general):
   - `GET /api/v1/juegos` como taquilla → **200**.
   - `GET /api/v1/reportes/ventas-totales` como taquilla → **200** (filas acotadas por #85).
   - `GET /api/v1/tickets` como agencia → **403**.
   - `GET /api/v1/users` como taquilla → **200** con solo su ficha.
7. Recién entonces enrutar tráfico a las rutas migradas.
8. **Rollback**: desplegar la imagen anterior y, si la matriz quedó inconsistente, restaurar `role_has_permissions` desde el backup o re-ejecutar el seeder del tag anterior. El `entrypoint.sh` actual solo siembra cuando la tabla `migrations` no existe; **no** confiar en él para una BD existente.

## 8. Follow-ups

- **Reparenting** `taquilla → grupo` y `taquilla → agencia → grupo`: fuera de alcance (#84/#85), BE-04 no lo toca.
- **`view_tickets` / `manage_tickets`** eventuales si se quiere filtrar tickets a agencia: crear el permiso **junto con** el filtro correspondiente en `AlcanceService::applyToTickets()` en la misma change; abrirlo sin scoping repetiría la fuga que D4 evita.
- **CI**: corre en `main`. El push de este stack está diferido al cierre y se hará en un único push contra `main`.
- **W1**: agregar un test que ejecute el seeder dos veces sobre la misma BD y verifique conteos estables, ausencia de retirados y refresco de caché.
- **Sugerencia de cobertura negativa**: caso 403 explícito para `/juegos` y `/resultados` con usuario autenticado sin `view_juegos`/`view_resultados` (hoy la rama negativa la garantiza el middleware, no una request real).
- **Alias residuales**: `checkrole` y `role_or_permission` siguen registrados en `bootstrap/app.php` con 0 usos; retirarlos o documentar que se conservan a propósito.
- **W4**: ejecutar `php artisan route:list` en el deploy real para confirmar 0 `role:` en runtime.
- **Archivos `.md` ignorados**: `.gitignore:44` ignora `*.md`, así que los specs nuevos (`openspec/specs/{catalogo-permisos,autorizacion-capability}/spec.md`) y los dos archivos aditivos del archivo (`apply-progress.md`, `archive-report.md`) requieren `git add -f` al commitear el cierre. Los seis artefactos movidos ya están trackeados y `git mv` los dejó staged como renames.

## 9. Desviación TDD

El proyecto declara `apply.tdd: true`, pero la regla operativa del ciclo (impuesta por el usuario) prohíbe que el agente ejecute tests, migraciones o artisan. En consecuencia: el agente escribió los tests primero (RED) y el usuario ejecutó la suite (GREEN) y reportó la evidencia. No hay captura de fallo RED previa al GREEN en los artefactos; la verificación descansa en la suite completa reportada por el usuario. Esta desviación es deliberada y conocida, no un defecto de implementación.

## 10. Trazabilidad de artefactos

**Archivos del change** (leídos y luego archivados sin alteración; `diff -r` contra el snapshot post-movimiento: sin diferencias):
`proposal.md`, `design.md`, `tasks.md`, `verify-report.md`, `specs/catalogo-permisos/spec.md`, `specs/autorizacion-capability/spec.md`.

**Observaciones Engram leídas**:
- #777 `sdd/be04-permisos/proposal`
- #778 `sdd/be04-permisos/spec`
- #779 `sdd/be04-permisos/design`
- #780 `sdd/be04-permisos/tasks` (snapshot de planificación, superado por `tasks.md`)
- #781 `sdd/be04-permisos/apply-progress` (consolidado a `apply-progress.md`)
- #782 `sdd/be04-permisos/verify-report`

**Documentación de soporte leída**: `docs/dev/permisos-capabilities.md`, `docs/dev/pendientes-front.md`, `openspec/config.yaml`.

**Persistencia**: este reporte se guarda como `sdd/be04-permisos/archive-report` (type `architecture`, project `lotto-app`, `capture_prompt: false`).

## 11. Veredicto

**Archivado.** El change cumplió sus dos capabilities sin CRITICAL. Los warnings restantes son deuda de evidencia (W1) y una limitación operativa aceptada (W4); W2/W3 quedaron corregidos en `81c4b8f`. El ciclo SDD de BE-04 queda cerrado; el siguiente paso es el merge local y el push único a `main`, no otro ciclo SDD.

## Key Learnings

1. Archivar exige copiar specs y mover carpetas con shell + `diff -r`; el reporte es el único archivo aditivo excluido de la comparación.
2. El `verify-report` es un snapshot: W2/W3 se cerraron en el commit `81c4b8f` posterior y el archivo debe reportar el estado final, no el intermedio.
3. Un seeder idempotente sin test de doble ejecución deja deuda de evidencia aunque la implementación sea estructuralmente correcta.
4. Dar un permiso nuevo a un rol sin revisar `AlcanceService` puede despertar una query sin filtro y abrir una fuga de filas.
