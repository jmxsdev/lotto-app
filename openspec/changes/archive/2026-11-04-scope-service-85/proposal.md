# Proposal: Extraer el scoping jerárquico a un `AlcanceService` + policies (issue #85)

## Problema

No existe una capa de alcance de datos. Las reglas de "qué filas ve cada rol" viven dispersas en los controllers como escaleras `rol → columna` (`taquilla → agencia → grupo → banca → master → super_master`), copiadas y pegadas una y otra vez. La única policy con scoping es `ApuestaPolicy`; el resto son denegaciones manuales con estilos inconsistentes. Es deuda estructural de alto acoplamiento y alto riesgo de fuga cross-tenant.

## Evidencia actual (verificable en `backend/`, base `f364895`)

| Métrica | Valor | Dónde |
|---|---|---|
| `hasRole()`/`hasAnyRole()` en `app/` | **223** | 13 controllers de `Api/` + models/policies |
| Controllers con chequeo de rol (alcance) | **14** | Agencia, Apuesta, Auth, Banca, Cierre, Comision, Estadistica, Grupo, Juego, Reporte, Taquilla, Ticket, User + Pago (scope vía policy/ownership) |
| Escalera `rol → columna` copiada | **hasta 7×** | `ReporteController::{buildApuestaQuery,buildTicketQuery}`, `EstadisticaController`, `ApuestaController::{index,historial,resumen}`, `TicketController::{index,ganadores×2}`, `CierreController`, `JuegoController`, `TaquillaController`, `GrupoController`, `AgenciaController` |
| `abort(403` en `app/` | **76** | Cierre 19, Taquilla 15, Juego 14, Agencia 11… |
| `json(..., 403)` manuales | **67** | Banca 9, Auth 8, Juego 8, Taquilla 8… |
| `masterBancaIds()` en `app/` | **14 usos** (1 definición + 13 llamadas) | definición `User.php:181`; llamadas en `ComisionController`, `JuegoController` (×7), `UserController` |
| Policies existentes | **2** | `ApuestaPolicy` (con scoping), `UserPolicy` |
| Líneas en `Api/` | **6 286** (21 controllers) | `JuegoController` 1 291, `Taquilla` 572, `Ticket` 478, `User` 462, `Cierre` 428 |

Los helpers ya existen en `User` (`masterBancaIds()`, `masterBancaScope()`, `masterBancaChainScope()`, `masterBancaGroupScope()`, `masterCanAccessBanca()`) — semilla directa del servicio.

## Objetivos

- Una sola implementación del recorrido jerárquico (`taquilla → grupo → banca` y `taquilla → agencia → grupo → banca`).
- Policies por modelo que deleguen el alcance y la autorización de acción.
- Migrar los 14 controllers de a uno, sin mezclar refactor con cambios de comportamiento.
- Eliminar las 5+ reimplementaciones de la lógica de "bancas que administra un master".
- Unificar el estilo de denegación (un solo mecanismo, mensaje preservado).

## No-objetivos

- Corregir endpoints con alcance faltante/incompleto (#84, cerrado).
- Unificar `hasRole()` con `users.role` (#69, cerrado).
- Middleware `CheckRole` muerto (#82).
- Inconsistencia de reparenting `taquilla → grupo` / `taquilla → agencia → grupo` (issue propio).
- Convertir esto en global scopes de Eloquent (decisión posterior).

## Alcance (in scope)

1. **`App\Support\Scope\AlcanceService`**: API única — `applyToApuestas`, `applyToTickets`, `applyToCierres`, `alcanceDeEntidad`, `puedeGestionar` — que centraliza el recorrido jerárquico, delegando en los helpers de `User`.
2. **Policies por modelo**: `BancaPolicy`, `GrupoPolicy`, `AgenciaPolicy`, `TaquillaPolicy`, `TicketPolicy`, `CierrePolicy` delegando en `AlcanceService`.
3. **Migración por controller**: sustituir cada escalera por una llamada al servicio/policy. Orden por oleadas (ver más abajo).
4. **Reemplazo de `masterBancaIds()`**: los 13 call sites pasan por `AlcanceService`; `grep -c "masterBancaIds()" app/` baja a **1** (la definición).
5. **Unificación de denegación**: un solo mecanismo (policy → `AuthorizationException` o `abort(403)`), **preservando el mensaje exacto** de cada endpoint para no romper contratos.
6. **Docs**: documentar el modelo de alcance y el orden de precedencia en `docs/dev/` (arquitectura backend).

## Decisión de nombres

- **Clase**: `AlcanceService` (el issue la nombra así en el cuerpo; el título dice "ScopeService").
- **Namespace**: `App\Support\Scope\` (coherente con `App\Support\` existente: `PremiosOficiales`, `ReversalPairQuery`, `Texto`). Los `App\Services\*` son servicios de negocio transaccionales; el alcance es un concern transversal distinto.
- Las policies en `App\Policies\` siguiendo el auto-discovery de Laravel.

## Estrategia de cero cambio de comportamiento

- **Baseline congelado**: `SuperBancaScopeTest`, `AgenciaScopeTest`, `MastersPanelTest`, `RoleAuthorizationTest`, `LimitesScopedApiTest`, `TicketMasterScopeTest`, `ApuestaScopePolicyTest`, `PagoEgresoScopeTest`, `AlcanceJerarquicoUsuariosTest`, `CreatedByTest` deben pasar **sin modificarse**.
- **Migración incremental**: un controller por PR; la suite se corre tras cada work unit (el usuario ejecuta `composer test`, no el agente).
- **Comparación antes/después**: cada PR reemplaza una escalera por la llamada equivalente; el SQL generado y los status/mensajes de denegación deben ser idénticos.
- **Prohibido mezclar**: ningún PR de refactor introduce cambios de comportamiento, mensajes ni status nuevos.

## Approach

1. Crear `AlcanceService` + 6 policies como capa **aditiva** (nadie la consume todavía): verificar que el baseline sigue verde.
2. Migrar controllers por oleadas, reemplazando escaleras por `AlcanceService` y denegaciones por policies.
3. Cerrar con docs y la verificación `grep masterBancaIds() == 1`.

## Orden de migración (oleadas, ≤800 líneas por PR)

| Oleada | Controllers | Líneas | PRs sugeridos |
|---|---|---|---|
| 0 — Fundación | `AlcanceService` + 6 policies (aditivo) | ~300 nuevas | PR 0 |
| 1 — Solo-listado | `EstadisticaController`, `ReporteController`, `ApuestaController`, `TicketController` | 115 / 262 / 350 / 478 | 1a (Estadistica+Reporte 377), 1b (Apuesta 350), 1c (Ticket 478) |
| 2 — CRUD entidades | `BancaController`, `AgenciaController`, `GrupoController`, `TaquillaController` | 216 / 307 / 388 / 572 | 2a (Banca+Agencia 523), 2b (Grupo 388), 2c (Taquilla 572) |
| 3 — Varios | `ComisionController`, `AuthController`, `UserController`, `PagoController` | 142 / 164 / 462 / 272 | 3a (Comision+Auth 306), 3b (User 462), 3c (Pago 272) |
| 4 — Volumen (último) | `CierreController`, `JuegoController` | 428 / 1 291 | 4a (Cierre 428), 4b (Juego, partido por grupos de métodos: catálogo / límites / resultados) |
| 5 — Cierre | docs + verificación de conteos | — | PR final |

`JuegoController` (1 291 líneas) **excede** el corte y se parte por grupos de métodos; `masterBancaIds()` desaparece de `app/` cuando cierran oleadas 3 y 4.

## Capabilities

> Contrato con sdd-spec. Este change es refactor puro: **cero cambio de comportamiento a nivel de spec**.

### New Capabilities

- None.

### Modified Capabilities

- None.

## Affected Areas

| Area | Impacto | Descripción |
|---|---|---|
| `app/Support/Scope/AlcanceService.php` | New | Recorrido jerárquico único |
| `app/Policies/{Banca,Grupo,Agencia,Taquilla,Ticket,Cierre}Policy.php` | New | Delegan en `AlcanceService` |
| `app/Http/Controllers/Api/*` (14) | Modified | Escaleras → servicio/policies |
| `app/Models/User.php` | Modified | `masterBancaIds()` queda como helper de bajo nivel (única definición) |
| `docs/dev/` | Modified | Modelo de alcance + orden de precedencia |

## Risks

| Riesgo | Probabilidad | Mitigación |
|---|---|---|
| Superficie grande (14 controllers, ~6 286 líneas) | Alta | Migración por controller en PRs ≤800 líneas; baseline de tests intacto |
| Tests que asumen mensajes/status de denegación | Media | Preservar mensaje exacto; unificar mecanismo, no texto |
| Acoplamiento a `master_id`/`created_by` | Media | `AlcanceService` solo delega en helpers existentes; no reinterpreta la jerarquía |
| `JuegoController`/`CierreController` de alto volumen | Media | Se dejan para el final; `Juego` se parte por grupos de métodos |
| Regresión por orden de ramas (el orden `taquilla→agencia→grupo→banca` varía entre controllers) | Media | `AlcanceService` respeta el orden exacto del controller migrado en cada PR |

## Rollback Plan

- Refactor sin migraciones: revertir el PR/commit por `git revert` o checkout de la imagen previa (`IMAGE_TAG`), sin pérdida de datos.
- Como cada PR es autónomo, se revierte la oleada afectada sin arrastrar las demás.
- La capa `AlcanceService`/policies es aditiva en la oleada 0: se puede revertir sin tocar controllers.

## Dependencies

- Ninguna externa. Requiere que `#84` (alcance-jerarquico) y `#69` (autorizacion-rol-unico) estén en main (lo están, base `f364895`).
- `masterBancaIds()` y demás helpers de `User` ya existen — no se reimplementan, se reutilizan.

## Success Criteria

- [ ] Ningún controller de `Api/` compara roles para decidir qué filas ve, salvo autorización de acción puntual.
- [ ] `AlcanceService` es la única implementación de `taquilla → grupo → banca` y `taquilla → agencia → grupo → banca`.
- [ ] `grep -c "masterBancaIds()" app/` devuelve **1**.
- [ ] Los tests de scope (`SuperBancaScopeTest`, `AgenciaScopeTest`, `MastersPanelTest`, `RoleAuthorizationTest`, `LimitesScopedApiTest`) pasan **sin modificar**.
- [ ] Docs del modelo de alcance y orden de precedencia en `docs/dev/`.
- [ ] `composer test` en verde (ejecutado por el usuario, no por el agente).

## Impacto CI/CD

- **CI solo en `main`**: los tests no corren en la rama feature; el usuario corre `composer test` por work unit.
- **Sin migraciones**: sin impacto de deploy ni rollback de datos; solo binario.
- **Deploy local stacked-to-main**: PRs viven en local; GitHub no se toca hasta cerrar #85 + BE-04.

## Relación con BE-04

- Este change consolida el **alcance por jerarquía** en un servicio/policies, sin tocar permisos (`permission:`/`Gate::before`). BE-04 introducirá después el catálogo de permisos sobre esta base, que quedará lista para reemplazar el alcance por capabilities puntuales sin volver a tocar controllers.
