# Apply Progress — scope-service-85 (issue #85)

> Consolidado desde la memoria Engram del ciclo (`sdd/scope-service-85/apply-progress`, obs #773).
> Regla del ciclo: el agente **no** ejecuta tests, migraciones ni artisan; el maintainer corre los comandos localmente.
> Rutas de código relativas a `backend/`.

## Modo

Standard. El ciclo no se ejecutó como ciclos RED/GREEN de Strict TDD: la regla del orquestador es que el usuario corre los tests y cada WU es un slice de refactor/docs cuya aceptación es la suite existente sin modificar. La evidencia por work unit queda registrada abajo.

## Work units

| WU | Contenido | Rama | Base |
|---|---|---|---|
| 0a-1 | `AlcanceService` (listados base) + paridad | `feat/85-0a-alcance-service` | `feature/alcance-scope-service-85` |
| 0a-2 | `AlcanceService` usuarios/entidades + paridad | `feat/85-0a2-usuarios-entidades` | `feat/85-0a-alcance-service` |
| 0c | Visibilidad/gestión por entidad + matriz rol×entidad | `feat/85-0c-entidades` | `feat/85-0a2-usuarios-entidades` |
| 0b | 6 policies + registro explícito + `AlcancePoliciesTest` | `feat/85-0b-policies` | `feat/85-0a2-usuarios-entidades` |
| 1a | `EstadisticaController` + `ReporteController` | `feat/85-1a-estadistica-reporte` | `feat/85-0b-policies` |
| 1b | `ApuestaController` + `ApuestaPolicy` | `feat/85-1b-apuesta` | `feat/85-1a-estadistica-reporte` |
| 1c | `TicketController` | `feat/85-1c-ticket` | `feat/85-1b-apuesta` |
| 2a | `BancaController` + `AgenciaController` | `feat/85-2a-banca-agencia` | `feat/85-1c-ticket` |
| 2b | `GrupoController` | `feat/85-2b-grupo` | `feat/85-2a-banca-agencia` |
| 2c | `TaquillaController` | `feat/85-2c-taquilla` | `feat/85-2b-grupo` |
| 3a | `UserController` | `feat/85-3a-user` | `feat/85-2c-taquilla` |
| 3b | `ComisionController` | `feat/85-3b-comision` | `feat/85-3a-user` |
| 4a | `CierreController` | `feat/85-4a-cierre` | `feat/85-3b-comision` |
| 4b | `JuegoController` (límites + visibilidad) | `feat/85-4b-juego` | `feat/85-4a-cierre` |
| 5 | Docs + `ArquitecturaAlcanceTest` + conteos | `feat/85-5-docs-arquitectura` | `feat/85-4b-juego` |
| 4c | Remediation: visibilidad Juego + Comisión | `feat/85-4c-juego-comision` | `feat/85-5-docs-arquitectura` |

## REMEDIATION WU-4c

### Disparador

Verify FAIL (C1): REQ-2 parcialmente incumplido. `JuegoController::entidadesVisiblesPorTipo` (`JUEGO_VIS`), la resolución de ids de `expandirTipoAlcance` y `ComisionController::bancasEnAlcance` todavía decidían filas por rol. W1: 13/19 `abort` de `CierreController` no pasaban por `denegar()`.

### Qué cambió

- `AlcanceService::entidadesVisibles(User $actor, string $tipo, ?array $raiz = null): Collection` — copia literal del contrato `JUEGO_VIS`: orden de roles `banca > master > grupo > agencia > super_master`, `masterBancaIds`/`bancasDeMaster`, `super_master` sin filtro, intersección de raíz (`banca`→grupos/taquillas, `grupo`→taquillas), `orderBy('id')->get(['id','name'])`, rol desconocido → `collect()` (fail-closed).
- `AlcanceService::idsPorTipo(User $actor, string $singular): Collection` — copia literal del ladder de ids de expansión de alcance para `banca|grupo|taquilla`, con su orden de ramas original (`banca|agencia` antes que `master`; `grupo|agencia` → `[$actor->grupo_id]`), `bancasDeMaster` y `denegar('No tienes acceso a la entidad raíz del alcance.')` para roles desconocidos/fuera de catálogo.
- `AlcanceService::bancasParaComisiones(User $actor): ?array` — `master` → `bancasDeMaster()->all()`; `super_master` → `null`; resto → `denegar('No autorizado: rol sin alcance de comisiones.')`.
- `JuegoController::entidadesVisiblesPorTipo` pasa a wrapper de una línea sobre `AlcanceService::entidadesVisibles` (ambos call sites sin cambios); `expandirTipoAlcance` resuelve ids vía `AlcanceService::idsPorTipo`.
- `ComisionController::bancasEnAlcance` pasa a wrapper de una línea sobre `AlcanceService::bancasParaComisiones`.
- **No tocado**: guards 422, `updateLimites`, `batchLimites`, `authorizeEscrituraLimite`, `resolverRaiz`, `objetivosDesdeEntidades`, `destroyLimite` y los guards de escritura de #84.
- Spec REQ-5 enmendado: la capa de alcance MUST usar `denegar()`; la autorización de acción puntual MAY conservar `abort(403)`/`json(...,403)` con status/body idénticos. Nuevo escenario. REQ-2 aclarado: las decisiones de filas (`applyTo*`, `entidadesVisibles`, `idsPorTipo`, `bancasParaComisiones`) viven en `AlcanceService`.
- `docs/dev/alcance-jerarquico.md`: el consumidor de `JUEGO_VIS` es `entidadesVisibles` (ladder en el servicio); orden de ramas de `idsPorTipo` documentado; sección de denegación distingue capa de alcance vs autorización de acción; `JUEGO_VIS` sale de §7 (excepciones deliberadas); `AlcanceJuegoTest` agregado a §8.
- `tasks.md`: línea de denegaciones de 4a marcada `[x]` con nota «6/19 scope denials migrados; 13 action-authorization aborts permanecen por diseño (REQ-5)»; nueva sección «4c — Visibilidad Juego + Comisión (remediación verify)» con tareas `[x]`; notas de 4a/4b actualizadas; criterio global REQ-2 marcado; `AlcanceJuegoTest` agregado al criterio de tests nuevos.

### Archivos (WU-4c)

- Modificado: `app/Support/Scope/AlcanceService.php` (+197 líneas: `entidadesVisibles`, `idsPorTipo`, `bancasParaComisiones`).
- Modificado: `app/Http/Controllers/Api/JuegoController.php` (130 líneas cambiadas; ladder reemplazada por delegación, wrapper de una línea).
- Modificado: `app/Http/Controllers/Api/ComisionController.php` (16 líneas cambiadas; wrapper de una línea).
- Creado: `tests/Feature/AlcanceJuegoTest.php` (367 líneas).
- Modificado: `openspec/changes/scope-service-85/specs/arquitectura-alcance/spec.md` (REQ-2 + REQ-5 + escenario nuevo).
- Modificado: `openspec/changes/scope-service-85/tasks.md`.
- Modificado: `docs/dev/alcance-jerarquico.md`.

### Paridad de comportamiento (WU-4c)

- `entidadesVisibles`: mismas filas, mismo `orderBy('id')`, mismas intersecciones de raíz, mismo fail-closed con colección vacía.
- `idsPorTipo`: mismos ids, misma precedencia de ramas (incluido el orden distinto de `JUEGO_VIS`), misma semántica de colección vacía para master sin bancas, mismo 403 vía `denegar()`.
- `bancasParaComisiones`: `master` → ids de sus bancas (posiblemente vacío), `super_master` → `null`, resto → mensaje 403 exacto.
- Sin cambios de status/body/mensajes; los controllers solo delegan.

### Evidencia (WU-4c)

| Evidencia | Valor |
|---|---|
| Comando focalizado | `cd backend && php artisan test --filter='Juego|Limites|Comision|ArquitecturaAlcanceTest'` — NO ejecutado por el agente (regla: lo corre el usuario). |
| Harness de runtime | N/A. Checks estáticos: `php -l` limpio en `AlcanceService.php`, `JuegoController.php`, `ComisionController.php` y `AlcanceJuegoTest.php`; grep muestra que `entidadesVisiblesPorTipo` y `bancasEnAlcance` solo delegan; `ComisionController` no tiene `hasRole`/`hasAnyRole`; las líneas `hasRole` restantes de `JuegoController` son autorización de acción o guards. |
| Frontera de rollback | Revertir los 3 métodos nuevos del servicio + los 2 wrappers de controller + borrar `AlcanceJuegoTest.php` y revertir spec/docs/tasks; guards 422 y alcance de escritura de #84 intactos. |

### Presupuesto (WU-4c)

`git diff --stat`: 6 archivos trackeados, 260 inserciones / 151 eliminaciones; test nuevo sin trackear de 367 líneas. Total ~778 líneas cambiadas, bajo el presupuesto de 800. Sin commit, sin migraciones, sin artisan de runtime, sin GitHub.

### Desviaciones (WU-4c)

- El prompt hablaba de «resolución de ids de `expandirAlcance`»; el ladder de roles vivía en `expandirTipoAlcance` (`expandirAlcance` solo resuelve descendientes después de `entidadDentroDelAlcance`). Ese ladder se extrajo literal a `idsPorTipo`; `expandirAlcance` no se tocó.
- `entidadesVisiblesPorTipo`/`bancasEnAlcance` se conservaron como wrappers privados de una línea en vez de eliminarse, preservando call sites y minimizando el diff.

## Tareas completadas (`tasks.md`)

### Fase 0 — Fundación

- [x] 0a-1 `AlcanceService` (`final class`) con `applyToApuestas`, `applyToTickets`, `applyToCierres`, `bancasDeMaster`, `denegar`.
- [x] 0a-1 Delegar el recorrido jerárquico en helpers de `User` (`masterBancaIds`, `masterBancaScope`, `masterBancaChainScope`, `masterBancaGroupScope`, `masterCanAccessBanca`).
- [x] 0a-1 Constantes de precedencia `APUESTAS`, `TICKETS`, `ENTIDADES`, `CIERRE_ITEM` + mensajes de vínculo faltante.
- [x] 0a-2 `applyToUsuarios` (`created_by` como metadata de fila) y `applyToEntidades` + `applyToBancas`/`Grupos`/`Agencias`/`Taquillas`/`Limites`.
- [x] 0a-2 Constantes `LIMITES`, `POLICY` y `JUEGO_VIS`.
- [x] 0c `alcanceDeEntidad` / `puedeGestionar` / `puedeOperarTaquilla`.
- [x] 0c Helpers de visibilidad/gestión por entidad.
- [x] 0c `AlcanceEntidadesTest` con matriz rol×entidad.
- [x] 0c Diferimiento de `entidadesVisibles` cerrado en WU-4c (implementado en `AlcanceService` y consumido por `JuegoController`).
- [x] 0b Cada policy delega scope/acción en `AlcanceService` (una línea por método).
- [x] 0b Registrar las 6 policies en `AppServiceProvider::boot()`.
- [x] 0b `AlcancePoliciesTest` con matriz rol×entidad a través de `Gate`.

### Fase 1 — Listados

- [x] 1a `EstadisticaController`: ladder → `AlcanceService::applyToApuestas`.
- [x] 1a `ReporteController::{buildApuestaQuery,buildTicketQuery}`: ladder → `applyTo*` (Tickets usa `APUESTAS`, no `TICKETS`, para paridad con agencia).
- [x] 1a Denegaciones manuales → `AlcanceService::denegar()` con mensaje idéntico.
- [x] 1b `ApuestaController::{index,historial,resumen}`: ladder → `applyToApuestas`.
- [x] 1b `ApuestaPolicy`: scoping propio reemplazado por delegación a `AlcanceService` (contrato sin cambios; lookup con `app()`, sin constructor).
- [x] 1c `TicketController::{index,ganadores}`: ladder (×3) → `applyToTickets`.
- [x] 1c Denegaciones preservadas con mensaje exacto (`show` → `alcanceDeEntidad` + `response()->json('No autorizado.', 403)`).

### Fase 2 — CRUD de entidades

- [x] 2a `BancaController`: ladder → `applyToEntidades('banca')` en `index`; `alcanceDeEntidad`/`puedeGestionar` para `show`/`update`/`toggle`/`destroy`.
- [x] 2a `AgenciaController`: `index` → `applyToEntidades('agencia')`; `authorizeAgenciaAccess` → `alcanceDeEntidad('agencia')`; `authorizeGestion` → `puedeGestionar('agencia')` cuando hay entidad.
- [x] 2a Denegaciones manuales → decisión del servicio con status/body/mensaje exactos por endpoint (`authorizeGrupoAccess` intacto).
- [x] 2b `GrupoController`: ladder de `index` → `applyToEntidades('grupo', 'No tienes permiso para ver grupos.')`; `show` → `alcanceDeEntidad('grupo')`; `update`/`toggle`/`destroy` → `puedeGestionar('grupo')`; denegación unificada.
- [x] 2b `store`/validación/`banca_id`/límites/transacciones/cascadas preservados; `authorizeBancaAccess` intacto.
- [x] 2c `TaquillaController`: ladder de `index` → `applyToEntidades('taquilla', 'No tienes permiso para ver taquillas.')`; `show`/`update`/`toggle`/`destroy` → `authorizeTaquillaAccess` delegando en `alcanceDeEntidad('taquilla')` + `denegar('No tienes acceso a esta taquilla.')`.
- [x] 2c `puedeOperarTaquilla`/agencia→grupo: no existía call site en el controller; la variante queda expuesta por `TaquillaPolicy::operar`. Las escrituras NO usan `puedeGestionar('taquilla')` (ver Desviaciones).
- [x] 2c `store` intacto (derivación `agencia_id`/`grupo_id`, validación, `activation_code`, MAC/fingerprint, `active`, transacción, límites, `authorizeGrupoAccess`/`authorizeAgenciaAccess`).

### Fase 3 — Usuarios y comisiones

- [x] 3a `UserController::index`: ladder → `applyToUsuarios(User::query(), $user, 'No tienes permisos para ver usuarios.')`; se elimina el único call site de `masterBancaIds()` en el controller.
- [x] 3a Denegación unificada con mensaje exacto: 403 de vínculo faltante vía `MENSAJE_SIN_BANCA`/`_SIN_GRUPO`/`_SIN_AGENCIA` (texto idéntico); 403 de rol no permitido conserva `'No tienes permisos para ver usuarios.'` vía `denegar()`.
- [x] 3b `ComisionController`: se elimina el call site de `masterBancaIds()`; la decisión de scope se delega en WU-4c a `AlcanceService::bancasParaComisiones` (master→`bancasDeMaster()->all()`, super_master→`null`, resto→403 exacto).
- [x] 3b Denegación unificada: `abort(403, 'No autorizado: rol sin alcance de comisiones.')` → `AlcanceService::denegar()` con mensaje idéntico (ahora dentro de `bancasParaComisiones`).

### Fase 4 — Volumen

- [x] 4a `scopeCierresPara`: ladder → `AlcanceService::applyToCierres($query, $user, 'No tienes permisos para ver cierres de caja.')`; orden `ENTIDADES` y mensajes de vínculo faltante idénticos. El body 403 legado se preserva con `response()->json(['message' => ...], 403)` capturando el 403 del servicio (un `abort` pelado agregaría metadata de debug con `APP_DEBUG=true`).
- [x] 4a `show` → `authorizeCierreAccess` delega en `alcanceDeEntidad('cierre')`; `cierreVisible` reproduce el ladder `ENTIDADES` predicado por predicado y luego `denegar('No tienes acceso a este cierre.')` mantiene status/mensaje.
- [x] 4a `entidadesVisibles`: sin consumidor en `CierreController`; el orden taquilla-first de `CIERRE_ITEM` se preserva literal en `assertTaquillaEnAlcance`. `entidadesVisibles` se implementó en WU-4c para Juego.
- [x] 4a Denegaciones 19 `abort(403)` → `denegar()`: parcial 6/19. Solo `authorizeCierreAccess` se migró; 13 permanecen por diseño en `assertTaquillaEnAlcance` (6) y `resolveTaquillaParaCierre` (7). Nota: «6/19 scope denials migrados; 13 action-authorization aborts permanecen por diseño (REQ-5)».
- [x] 4b `limites()`: ladder → `applyToEntidades('limite')` con el guard de rol por delante (mismo envelope 403, mismo orden de validación); `entidadDentroDelAlcance` → `alcanceDeEntidad(banca|grupo|taquilla)`, conservando el corto-circuito `super_master` por paridad.
- [x] 4b `entidadesVisiblesPorTipo` mantuvo `JUEGO_VIS` en 4b; WU-4c movió ese ladder a `AlcanceService::entidadesVisibles` con el mismo contrato (sin reutilizar `applyToEntidades`/`ENTIDADES`).
- [x] 4b Los 7 call sites de `masterBancaIds()` reemplazados por `bancasDeMaster()`; `grep -n masterBancaIds` en el controller queda vacío; semántica `Collection`/array y vacío = NADA preservada.
- [x] 4b 4 `abort(403, 'No tienes acceso a la entidad raíz del alcance.')` → `denegar()` con texto idéntico; guards `JsonResponse` de rol y denegaciones de escritura de #84 intactos.
- [x] 4b Guards 422 intactos (validación de `limites`/`listarLimites`/`updateLimites`/`batchLimites`, XOR de scope, tope de 500 entidades, raíz de taquilla).
- [x] 4c `AlcanceService::entidadesVisibles` (JUEGO_VIS literal), `idsPorTipo` (ladder de ids literal) y `bancasParaComisiones` (master/super/resto).
- [x] 4c `JuegoController::entidadesVisiblesPorTipo` y `bancasEnAlcance` delegan (wrappers de una línea); `expandirTipoAlcance` usa `idsPorTipo`.
- [x] 4c `AlcanceJuegoTest` (dos árboles A/B) para `entidadesVisibles` por rol+roots, `idsPorTipo` por rol+fail-closed y `bancasParaComisiones` master/super/403.

### Fase 5 — Docs y arquitectura

- [x] 5 Documentado el modelo rol/columna, precedencia por contexto, reglas clave, guía de migración, mecanismo de denegación y excepciones deliberadas en `docs/dev/alcance-jerarquico.md`; enlazado desde `docs/dev/arquitectura-backend.md` §3.2.
- [x] 5 `ArquitecturaAlcanceTest`: `AlcanceService` final; 1 definición de `masterBancaIds` en `User`; 0 usos en `app/Http/Controllers`; único consumidor externo `AlcanceService` (incluye helpers `masterBanca*Scope`); conservador, sin aserciones de `hasRole`.
- [x] 5 Conteos verificados: `->masterBancaIds()` en `app/` = 6 líneas (4 helpers internos de `User` + 2 en `AlcanceService`), 0 en controllers, 1 definición; spec REQ-4 y design actualizados porque el criterio literal `grep -c == 1` era falso.

## Archivos

- WU-5: creado `docs/dev/alcance-jerarquico.md`; modificado `docs/dev/arquitectura-backend.md` (§3.2).
- WU-5: creado `tests/Feature/ArquitecturaAlcanceTest.php` (167 líneas).
- WU-5: modificados `tasks.md`, `spec.md`, `design.md`.
- WU-4b: `JuegoController.php` (136 líneas cambiadas).
- WU-4a: `CierreController.php` (116).
- WU-3b: `ComisionController.php` (12; +16 en 4c).
- WU-3a: `UserController.php` (76).
- WU-2c: `TaquillaController.php` (108).
- WU-2b: `GrupoController.php` (99).
- WU-2a: `BancaController.php` + `AgenciaController.php` (~160).
- WU-1c/1b/1a: `TicketController`, `ApuestaController` + `ApuestaPolicy`, `EstadisticaController` + `ReporteController`.
- WU-0b: 6 policies + `AppServiceProvider` + `AlcancePoliciesTest`.
- WU-0a/0c: `AlcanceService` + tests.

## Evidencia por work unit

| WU | Comando focalizado (NO ejecutado por el agente; lo corre el usuario) | Frontera de rollback |
|---|---|---|
| 0a-1/0a-2 | `php artisan test --filter=AlcanceServiceScopeTest` | revertir `AlcanceService` + su test (aditivo) |
| 0c | `php artisan test --filter=AlcanceEntidadesTest` | revertir helpers de visibilidad/gestión |
| 0b | `php artisan test --filter=AlcancePoliciesTest` | revertir 6 policies + líneas del provider |
| 1a | `php artisan test --filter='Estadistica|Reporte'` | revertir 2 controllers |
| 1b | `php artisan test --filter='Apuesta'` | revertir `ApuestaController` + `ApuestaPolicy` |
| 1c | `php artisan test --filter='Ticket'` | revertir `TicketController` |
| 2a | `php artisan test --filter='Banca|Agencia'` | revertir 2 controllers |
| 2b | `php artisan test --filter='Grupo'` | revertir `GrupoController` |
| 2c | `php artisan test --filter='Taquilla'` | revertir `TaquillaController` |
| 3a | `php artisan test --filter='UsuarioRolApi|GestionUsuarios|AlcanceJerarquicoUsuarios'` | revertir `UserController` |
| 3b | `php artisan test --filter='Comision'` | revertir `ComisionController` |
| 4a | `php artisan test --filter='Cierre|Cuadre'` | revertir `CierreController` |
| 4b | `php artisan test --filter='Limites|Juego'` | revertir `JuegoController` (guards 422 intactos) |
| 4c | `php artisan test --filter='Juego|Limites|Comision|ArquitecturaAlcanceTest'` | revertir métodos del servicio + 2 wrappers + `AlcanceJuegoTest` |
| 5 | `php artisan test --filter=ArquitecturaAlcanceTest` | revertir docs + test estático |

## Desviaciones (WUs anteriores)

- 4b: `entidadesVisiblesPorTipo` NO se delegó a `applyToEntidades` (`JUEGO_VIS` vs `ENTIDADES` habría cambiado 403s y precedencia multi-rol); luego se movió a `AlcanceService::entidadesVisibles` en 4c con el mismo contrato.
- 4b: predicados de `destroyLimite` dejados como estaban (semántica de grupo y mensajes distintos). `expandirTipoAlcance`/`expandirAlcance` conservaron sus tie-breaks de rol hasta 4c, que extrajo el ladder de ids literal a `idsPorTipo`.
- 4a: `assertTaquillaEnAlcance` NO se migró a `puedeOperarTaquilla` ni a `puedeGestionar('taquilla')`; `resolveTaquillaParaCierre` intacto. 13 `abort(403)` permanecen por diseño.
- 3b: `ComisionController` tenía exactamente UN call site de `masterBancaIds` (dentro de `bancasEnAlcance`); 4c movió esa decisión a `bancasParaComisiones`.
- 2c: las escrituras delegan en `alcanceDeEntidad`, no en `puedeGestionar` (`puedeGestionarTaquilla` agrega agencia→grupo y ensancharía escrituras de agencia por el grupo, #84/F1).
- 3a: el body del 403 de rol no permitido ahora lo produce `abort()` en vez de `response()->json()`; status/texto idénticos y la rama es inalcanzable por el middleware de ruta.

## Decisiones clave

- WU-4c: REQ-2 se cierra moviendo los ladders de filas restantes al servicio, no enmendando el spec; REQ-5 es el único enmendado para permitir `abort(403)` de acción puntual.
- WU-4c: `idsPorTipo` preserva el orden de ramas original (no `JUEGO_VIS`) verbatim: `banca|agencia` antes que `master`; `grupo|agencia` → `[$actor->grupo_id]`.
- WU-5: el test de arquitectura es file-allowlist; falla si `masterBancaIds` se dispersa a un archivo nuevo.
- WU-4a: preservar el envelope `JsonResponse` 403 capturando `HttpExceptionInterface` (`APP_DEBUG` cambiaría el body).
- Las denegaciones mantienen `abort(403,...)` vía `denegar()`; las policies devuelven `bool` y no se usan para mensajes custom.

## Presupuesto

- WU-4c: 260 inserciones + 151 eliminaciones trackeadas + 367 líneas de test nuevo = ~778. Bajo 800.
- WU-5: ~382. Anteriores: 0a-1 ~800 (partido en 0a-1/0a-2); 0b ~330; 1a ~130; 1b ~153; 1c ~56; 2a ~160; 2b ~101; 2c ~111; 3a ~78; 3b ~14; 4a ~121; 4b ~141. Todos bajo 800.

## Baseline

- Ningún test de baseline modificado en ningún WU.
- WU-4c: solo `AlcanceJuegoTest` nuevo; tests existentes de Juego/Límites/Comisión intactos.

## Riesgos / notas para verify

- WU-4c: el agente NO ejecutó tests (regla dura). Re-ejecutar el comando focalizado, luego `composer test` y `pint --test`.
- WU-4c: `entidadesVisibles`/`idsPorTipo` se copiaron literalmente; no normalizar sobre `ENTIDADES`/`applyToEntidades`.
- WU-4c: las líneas `hasRole` restantes en `JuegoController` son autorización de acción o guards (batch/update/destroy de límites, guard de rol del batch, corto-circuito `super_master` de `entidadDentroDelAlcance`), por diseño.
- WU-5: `ArquitecturaAlcanceTest` solo lee archivos, no DB.
- WU-4a: 13 `abort(403)` permanecen por diseño; REQ-5 documenta la distinción.
- CI: los tests no corren en ramas feature; la evidencia es local hasta que el usuario los ejecute.

## Siguiente

- WU-4c aplicado (sin commit, sin tests ejecutados, sin GitHub).
- Recomendado: `sdd-verify` para `scope-service-85` (re-verificación). El usuario ejecuta: `cd backend && php artisan test --filter='Juego|Limites|Comision|ArquitecturaAlcanceTest'`, luego `composer test` y `vendor/bin/pint --test`.
