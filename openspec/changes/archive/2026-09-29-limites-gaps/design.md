# Design: `limites-gaps` — cierre de gaps de configuración de límites

> Fase sdd-design · worktree `limites-gaps` (base `c375786` = origin/main) · español neutro; términos RFC 2119 en inglés.
> Deriva de `proposal.md` (D1–D5) y `specs/limites/spec.md` (R1–R8). Sin migración de BD. Sin tests en este fase.

## Enfoque técnico

Diffs quirúrgicos (sin reformatear código ajeno): el backend deja de aceptar/exponer `fraccion`/`limite_tiempo`, elimina `agencia_id` del endpoint legacy y borra código muerto; el panel expone "Limpiar" por fila sobre el DELETE ya existente y alinea gates de rol/nav con la API. Las columnas quedan en BD; `JuegoLimite` conserva fillable/casts. Cada work unit es BACKEND con RED→GREEN estricto; el panel no tiene runner, así que su verificación es `astro build` + QA manual (el RED se materializa como checklist que hoy falla).

## Decisiones de arquitectura

| # | Decisión | Opciones consideradas | Elección | Razón |
|---|---|---|---|---|
| A1 | Tratamiento de campos dormidos | (a) solo ocultar UI; (b) arreglar checkbox y conservar; (c) retirar de UI+API, columnas quedan | **c** (D2) | Cero lectores de negocio; elimina el bug del checkbox (nunca persiste `false`; ítem vacío → 422) sin migración. `porcentaje_pago`/`participacion` son de COMISIONES: intactos. |
| A2 | Cierre de exposición en respuestas crudas | (a) solo `serializarLimite`/`valoresPresentes` (mapa del explore); (b) `JuegoLimite::$hidden` con los 2 campos; (c) API Resources por endpoint | **b** | `limites()` legacy, PUT y batch devuelven el modelo crudo: sin `$hidden` seguirían exponiendo las columnas (R1 y criterio "API no expone"). Una línea, no toca fillable/casts/BD; (c) es sobre-ingeniería para 2 campos. |
| A3 | Contrato de `valoresPresentes` (origen) | (a) conservar contrato sin los 2 campos; (b) eliminarlo; (c) sin cambios | **a** | El panel consume `origen.valor` (`utils/limites.ts:160`); se quita `limite_tiempo` del loop (:1032) y el bloque `fraccion` (:1038-1040). |
| A4 | `agencia_id` en `GET /limites/{juego}` | (a) eliminar param (ignorado → 200); (b) implementarlo agencia→taquillas; (c) deprecar endpoint | **a** (D4) | Hoy produces 500 (columna inexistente); el panel usa `listarLimites`; agencia es passthrough (`jerarquia-agencias`); (c) es non-goal. |
| A5 | Dónde vive la acción Limpiar | (a) en cada página; (b) opciones del componente: `puedeEditar` + `eliminar(id)` | **b** (D5) | Un único punto de render/confirmación/recarga; las páginas solo inyectan el callback `apiFetch('DELETE', …)`. |
| A6 | Alcance de Limpiar | (a) entidad y scope; (b) solo modo entidad | **b** | En scope la celda representa N entidades y `valor.id` identifica UNA fila: un DELETE unitario dentro de una superficie masiva es sorpresivo; el vaciado masivo ya existe (batch `null` → borra fila). |
| A7 | Confirmación y recarga | (a) `confirm()` nativo; (b) `showModal(type:'confirm')`; (c) parche local sin recargar | **b + recarga** | Patrón existente (`bancas/detalle.astro:617`). Tras el 200: `tocadas.delete(clave)` + `cargarDatos()` + `construirLineas()` + `pintar()`; el origen heredado solo lo calcula el backend, un parche local lo dejaría obsoleto. Error → `showModal` sin recargar (conserva `tocadas`). |
| A8 | Roles en UI | (a) ocultar tabla a grupo/agencia; (b) solo lectura (inputs `disabled`, Guardar/Limpiar ocultos) | **b** (D3, R3) | La API ya permite GET y niega escritura; grupo/agencia necesitan auditar. `limites.astro` se mantiene visible en lectura; taquilla (GET 403) conserva "Sin acceso". |
| A9 | Modo creación | (a) mantener edición para quien crea la entidad; (b) uniformar `puedeEditar`/sin `eliminar` | **b** | Coherencia con D3. Trampa: en create mode la tabla precarga límites del padre *con* `id` (`grupos/detalle:440`, `taquillas:480`): por eso `eliminar` NO se inyecta cuando `isCreate` (evita borrar la fila padre). La asimetría backend (POST de entidad aún permite límites a grupo/agencia) se documenta como follow-up, fuera de alcance. |

## Flujo de datos

### Botón "Limpiar" (modo entidad, fila propia)

```
crearTablaLimites.pintar()
  └─ fila con valor?.id && opts.puedeEditar && opts.eliminar && modo==='entidad'
       └─ [Limpiar] .limpiar-btn[data-id][data-clave]
            │ click
            ▼
      showModal({type:'confirm'}) ──No──► fin
            │Sí
            ▼
      opts.eliminar(id) ──► página: apiFetch('DELETE', '/api/v1/limites/'+id)
            │                                  │
            │  403/404 ◄───────────────────────┤
            ▼                                  ▼ 200
      showModal({type:'error'})          tocadas.delete(clave)
      (sin recargar)                     datos = await opts.cargarDatos()
                                         lineas = construirLineas(); pintar()
                                              └─ celda sin valor.id → origen "hereda de …"
```

### Retiro de campos (aceptación/exposición)

```
PUT /limites/{juego}   ─ validate(sin rules dormidas) ─ only([4 campos]) ─ updateOrCreate
POST /limites/batch    ─ validate(sin rules) ─ JuegoLimiteService::CAMPOS(4) ─ aplicarItemLimite
POST bancas|grupos|taquillas ─ validarItems ─ persistirParaEntidad (misma ruta del service)
GET /limites (listarLimites) ─ serializarLimite(4 campos) / valoresPresentes(4)
GET /limites/{juego} (legacy) ─ modelos crudos ─ JuegoLimite::$hidden oculta dormidos
DELETE /limites/{limite} ─ sin cambios: es el endpoint que consume el botón Limpiar
```

## Mapa de cambios por archivo

### Backend

| Archivo | Acción | Puntos exactos | Cambio |
|---|---|---|---|
| `backend/app/Http/Controllers/Api/JuegoController.php` | Modify | `:178` | Quitar rule `agencia_id` de `$request->validate`. |
| | | `:214` | Comentario: quitar "agencia". |
| | | `:224-226` | Quitar bloque `isset($filtros['agencia_id'])`/`where('agencia_id', …)`. **NO tocar** `:202`, `:586`, `:813`, `:988` (agencia_id de taquillas). |
| | | `:393-394`, `:458-459` | Quitar rules `fraccion`/`limite_tiempo` (PUT y batch). |
| | | `:421-424` | `only([...])` queda con 4 campos. |
| | | `:714-722` | Borrar `authorizeLimitesWrite` + docblock (cero callers). |
| | | `:1020-1021` | `serializarLimite`: quitar `fraccion`/`limite_tiempo`. |
| | | `:1032` | `valoresPresentes`: loop de 4 campos (sin `limite_tiempo`). |
| | | `:1038-1040` | Quitar bloque `fraccion`. |
| `backend/app/Services/JuegoLimiteService.php` | Modify | `:27` | `CAMPOS` = 4 campos. |
| | | `:45-46` | Quitar rules `fraccion`/`limite_tiempo` de `validarItems`. |
| `backend/app/Models/JuegoLimite.php` | Modify | tras `:34` | Añadir `protected $hidden = ['fraccion', 'limite_tiempo'];` (A2). Fillable/casts/BD intactos. |
| `backend/routes/api.php` | Sin cambios | `:198-215` | Roles ya correctos (R3). |
| `BancaController` / `GrupoController` / `TaquillaController` | Sin cambios | — | Solo llaman `validarItems`/`persistirParaEntidad`; no validan los campos por su cuenta (verificado). |

### Panel

| Archivo | Acción | Puntos exactos | Cambio |
|---|---|---|---|
| `panel/src/utils/limites.ts` | Modify | `:9-12` | Doc: "limpiar/heredar" ya implementado por DELETE. |
| | | `:24-36` | Opciones nuevas: `puedeEditar?: boolean` (default `true`), `eliminar?: (id: number) => Promise<any>`. |
| | | `:48-55` | `CAMPOS`: 4 columnas, sin `tipo` (ya no hay checkbox). |
| | | `:97-104` | `valorInicial`: sin rama `fraccion`. |
| | | `:128-131`, `:146-155` | Cabecera/celdas: inputs con `disabled` si `!puedeEditar`; número único. |
| | | `:157-165` | Columna "Acciones" solo si `modo==='entidad' && puedeEditar && eliminar`; botón solo si `valor?.id`. |
| | | `:187-210` | `pintar()` enlaza `.limpiar-btn`: `showModal(confirm)` → `opts.eliminar(id)` → limpieza+recarga (diagrama). Import `showModal` desde `utils/modal.ts`. |
| | | `:196-209` | Handler de inputs sin rama checkbox. |
| `panel/src/pages/limites.astro` | Modify | `:30-33`, `:94-110` | Separar ver/editar: `puedeEditar = ['super_master','master','banca']`; grupo/agencia renderizan tabla en lectura; ocultar `.acciones` si `!puedeEditar`; pasar `puedeEditar`. |
| `panel/src/pages/bancas/detalle.astro` | Modify | junto a `:190`; `:246-247` área; `:393-431` | Añadir `canEditLimites = ['super_master','master','banca'].includes(role)` (≠ `canEditBanca`); ocultar `[data-panel="limites"] .panel-actions` si `!canEditLimites`; pasar `puedeEditar` y `eliminar: isCreate ? undefined : (id) => apiFetch('DELETE','/limites/'+id)`. |
| `panel/src/pages/grupos/detalle.astro` | Modify | `:200`; `:426-474` | Usar `canEditLimites` (hoy definido y jamás usado): gate de Guardar + `puedeEditar`/`eliminar`. |
| `panel/src/pages/taquillas/detalle.astro` | Modify | `:185`; `:466-501` | Ídem (ya definido, jamás usado); `eliminar` ausente en create. |
| `panel/src/layouts/AdminLayout.astro` | Modify | `:255-256` | Nav `/limites` si `role` ∈ `super_master\|master\|banca`; actualizar comentario. |
| `panel/src/components/LimitesTable.astro` | Delete | — | Muerto (0 imports verificados). |

## Interfaces / contratos

```ts
export interface OpcionesTablaLimites {
  modo: 'entidad' | 'scope';
  // …opciones existentes sin cambios…
  puedeEditar?: boolean;                    // default true; false ⇒ inputs disabled, sin Acciones
  eliminar?: (id: number) => Promise<any>;  // ausente ⇒ sin botón Limpiar (create mode y scope)
}
```

API afectada: `GET /limites/{juego}` ya no valida `agencia_id`: cualquier param extra no validado se descarta → 200 sin filtro. Payloads PUT/batch con `fraccion`/`limite_tiempo`: ignorados; única excepción documentada: ítem de batch cuyo único contenido sean campos dormidos ⇒ 422 del guard present-fields-only existente (`JuegoLimiteService:125-127`), semántica sin cambios.

## Plan TDD (RED → GREEN por work unit)

| WU | RED (falla hoy) | GREEN | Verificación |
|---|---|---|---|
| **1. Retiro de campos** | `LimitesScopedApiTest:127` → `assertArrayNotHasKey('fraccion'/'limite_tiempo')` (hoy falla). `test_limites_entidad_grupo_muestra_origen_heredado` (:135): sembrar `fraccion=1`,`limite_tiempo=30` en la fila padre y assert `origen.valor` sin claves. Nuevo `test_put_ignora_campos_dormidos` (`LimitesApiTest`): payload con dormidos + `limite_maximo` → hoy persiste `fraccion=true`/`limite_tiempo=30`. Nuevo `test_batch_ignora_campos_dormidos` (`LimitesScopedApiTest`). Nuevo `test_get_limites_legacy_no_expone_dormidos` (A2). Nuevo `JuegoLimiteServiceTest.php` (SHOULD): `CAMPOS` sin dormidos, `null`→delete fila, presentes-only, ítem sin campos → 422. `CrearEntidadConLimitesTest:77,95-96`: retirar `fraccion` del payload/assert; mover el caso "ignorado" al test nuevo. | Controller `:393-394`, `:421-424`, `:458-459`, `:1020-1021`, `:1032`, `:1038-1040`; Service `:27`, `:45-46`; Model `$hidden`. | `DB_DATABASE=lotto_test_motor php artisan test --filter='Limites\|CrearEntidad\|JuegoLimiteService'`; `vendor/bin/pint --test`. NO usar `composer test`. |
| **2. `agencia_id` + muerto** | Nuevos en `LimitesApiTest`: `test_get_limites_ignora_agencia_id` (con `agencia_id` real → hoy 500), `test_get_limites_agencia_id_inexistente_es_ignorado` (999999 → hoy 500 de validación/exists). | Controller `:178`, `:214`, `:224-226`; borrar `:714-722`. | Ídem comando + `rg authorizeLimitesWrite` → 0 resultados. DELETE existente `LimitesApiTest:256-318` intacto (regresión). |
| **3. Panel: Limpiar** | Sin runner de panel: checklist QA en rojo (no existe botón; columnas dormidas visibles). | `utils/limites.ts` completo + cableado `eliminar`/`puedeEditar` en las 3 páginas de detalle. | `npm run build` (workdir `panel`) + QA: (a) fila propia muestra Limpiar, heredada no; (b) confirm→DELETE→celda pasa a "hereda de …"; (c) create mode sin Limpiar; (d) error 403/404 muestra modal. |
| **4. Panel: roles/nav/muerto** | Pin backend `test_grupo_no_configura_limites` (PUT grupo → 403; hoy ya verde: regresión de R3) y checklist QA (grupo/agencia ven Guardar). | Gates en `limites.astro`, `bancas/grupos/taquillas detalle`; `AdminLayout`; borrar `LimitesTable.astro`. | `php artisan test --filter=RoleAuthorization` + `npm run build` + QA: inputs disabled, Guardar oculto para grupo/agencia; nav solo 3 roles. |

`taquilla/*` no se toca; la zona premios queda fuera.

## Work units / commits

| WU | Commit (convencional español) | Contenido | Rollback |
|---|---|---|---|
| 1 | `fix(limites): retira fraccion y limite_tiempo de la API` | Backend + tests WU1 | `git revert` del commit |
| 2 | `fix(limites): ignora agencia_id en GET /limites/{juego} y elimina código muerto` | Backend + tests WU2 | Ídem |
| 3 | `feat(panel): agrega acción Limpiar por fila en la tabla de límites` | `utils/limites.ts` + páginas | Ídem |
| 4 | `fix(panel): alinea roles y navegación de límites y retira componente muerto` | Gates/nav + delete + pin test | Ídem |

Cada WU es autónomo, verificable y revertible por separado; los tests viajan con su código. El pronóstico de presupuesto de review (400 líneas) corresponde a `sdd-tasks`.

## Riesgos y rollback

| Riesgo | Mitigación |
|---|---|
| Gate externo: `apply` no arranca hasta aviso del orquestador | Documentado; el design no ejecuta tests. |
| `$hidden` (A2) oculta los campos a cualquier consumidor de modelos crudos | 0 lectores verificados (grep app/tests/panel); tests de serialización nuevos. |
| DELETE accidental de fila padre en create mode | `eliminar` no se inyecta cuando `isCreate` (A9). |
| Carrera 404 (fila ya borrada) | `showModal` de error; MAY recargar si `err.status === 404`. |
| `tocadas` obsoleto tras limpiar | `tocadas.delete(clave)` antes de recargar. |
| Ítem batch con solo campos dormidos → 422 | Decisión explícita (A1); la semántica present-fields-only no se cambia. |

Rollback global: revertir los commits del worktree. Sin migración; `fraccion`/`limite_tiempo` siguen en BD con sus valores previos.

## Threat Matrix

N/A — no routing, shell, subprocess, VCS/PR automation, executable-file classification ni process integration. El único borrado es un HTTP DELETE a un endpoint existente con autorización jerárquica ya testeada.

## Open Questions

- [ ] ¿Actualizar los ejemplos de `collections/Limites/Configurar Limite*.yml` (aún listan los 2 campos)? Propuesta: no en este ciclo (se vuelven ejemplos ignorados, sin error); confirmar en verify.
- [ ] ¿Alinear el POST de creación de taquilla para que grupo/agencia no persistan límites iniciales (asimetría backend tras A9)? Follow-up fuera de alcance.
- [ ] ¿`JuegoLimiteServiceTest` es SHOULD (spec R8): confirmar si `sdd-tasks` lo programa en WU1 o lo difiere.
