# Exploración: configuracion-juegos

**Estado**: draft · **Fase**: explore · **Fecha**: 2026-09-26
**Alcance**: backend (Laravel 13/PHP 8.3) + panel (Astro). `taquilla/` NO se toca (otro agente). Comisiones y gaps de límites (§D de `docs/PENDIENTE.md`) FUERA de alcance por decisión del usuario.

---

## 1. Problema y objetivo

El panel no permite configurar los premios de un juego. Hoy solo lista `config.premio_multiplo` (espejo legacy) y hace un toggle que está roto en silencio. La fuente de verdad del dinero es `config.premios` (`{base, modalidades, comodines}`), leída por `PremiosEngine`; editarla hoy exige tocar BD a mano o usar `PUT /juegos/{id}` que **reemplaza `config` completo** (borraría `scraper`, `modalidades_permitidas`, etc.).

**Objetivo**: entregar un endpoint dedicado `PUT /juegos/{juego}/premios` con merge seguro sobre `config`, sincronización de espejos legacy, auditoría (`JuegoAuditoria`) y una UI mínima de edición en el panel — más el fix del toggle roto (P1), que es un bug de bajo costo ya documentado.

---

## 2. Estado actual (hallazgos con evidencia)

### 2.1 Backend — `JuegoController` (`backend/app/Http/Controllers/Api/JuegoController.php`)

| Hallazgo | Evidencia |
|---|---|
| `toggle()` exige `{active: bool}` | L37-41: `$request->validate(['active' => 'required\|boolean'])` |
| `toggle()` persiste + audita | L47-67: update `active` + `updated_by`; `JuegoAuditoria::create` con `accion` `'activar'`/`'desactivar'` y `cambios.before/after` |
| `update()` reemplaza `config` COMPLETO | L81-88: `$changes = $request->only(['name','config'])` → `$juego->update(array_merge($changes, ...))`; mandar `{config:{premios}}` borra el resto del JSON |
| `update()` sin validación de estructura de `config` | L76-79: solo `'config' => 'nullable|array'` |
| `reglas()` expone `premios` aditivo (D10) | L150-153: `$reglas['premios'] = app(PremiosEngine::class)->reglas($juego)`; filtra `modalidades_permitidas` en L145-147 |
| `show()` ya expone auditoría | L32-35: `$juego->load('pluginJuego', 'auditoria.user')` |
| Patrón de roles de escritura | `updateLimites()` L376-382: guard `super_master|master|banca`; validación con `Rule::in` (L388) |

**Rutas** (`backend/routes/api.php` L117-127): lectura (`GET /juegos`, `GET /juegos/{id}`) cualquier autenticado; escritura (`PUT /juegos/{id}`, `PATCH /juegos/{id}/toggle`) bajo `role:super_master|master` (L120-123). El endpoint nuevo de premios debe entrar en ese mismo grupo.

### 2.2 Auditoría — `JuegoAuditoria` (`backend/app/Models/JuegoAuditoria.php`)

- Tabla `juego_auditoria`; fillable `juego_id, user_id, accion, cambios`; `cambios` cast array (L11-17).
- Se crea SOLO en `toggle` y `update` (grep: `JuegoController.php` L59 y L91). Relación en `Juego.php` L32-35.

### 2.3 Fuente de verdad — `PremiosOficiales` (`backend/app/Support/PremiosOficiales.php`)

- `CATALOGO` (L27-223): 21 juegos con `{base, modalidades, comodines, active}`; `la-ricachona` sin `base` (sin fuente oficial, REQ7).
- `configPara($slug)` (L326-359): genera `premio_multiplo` = base (L338), `premios` canónico (L341-345), espejo legacy `modalidades` (L349) vía `ESPEJO_MODALIDADES` (L233-266, identidad si no mapeada) + `ESPEJO_EXTRA` (L279-281: `terminal-activo` → `terminal` = base), `comodines` (L356).
- Consumido por: migración de backfill `2026_09_17_000002_backfill_premios_config_juegos.php` (L38: `array_merge` — ya MERGE sobre config existente) y los 21 seeders.

**Conclusión**: la lógica de sincronización de espejos YA EXISTE en `configPara()`. El endpoint nuevo debe reutilizarla (o extraerla a un servicio) para no duplicar el mapa `ESPEJO_MODALIDADES` en el controlador.

### 2.4 Motor — `PremiosEngine` (`backend/app/Services/PremiosEngine.php`)

- Lee `config.premios` en todos los caminos: `premioPosible` L87, `reglas` L112, `multiplicadorPara` L121-122 (fallback transicional a `premio_multiplo` para base), `multiplicadorConComodines` L134.
- `premioPosible` L90-92: modalidad declarada inexistente → premio 0 (rechazo silencioso). Validar claves de modalidad en el endpoint evita aceptar configs que luego pagan 0.
- Comodines L139-151: `flag|letra|numero` reemplazan (gana el mayor), `palabra` acumulativa suma; tipos fuera de ese set se ignoran.
- Gate de inactividad L41-43 y L77-79: juego inactivo → premio 0 (venta y liquidación).

### 2.5 Export — `JuegoCatalogoService` + `JuegosExportCommand`

- `JuegoCatalogoService::generar()` L35-49: exporta `premio_multiplo` (L40), `premios` (L41), `active` y `vendible` (= `active`, L42-43), `comodines`/`modalidades` espejos (L44-45).
- `JuegosExportCommand` → `docs/juegos.json` (raíz del repo).
- **Contrato testeado**: `backend/tests/Feature/JuegosJsonTest.php` L80-109 — el catálogo generado DEBE coincidir con el archivo commiteado. Editar premios sin re-exportar rompe la suite; re-exportar sin actualizar la copia bundled de la taquilla deja a la taquilla desactualizada (riesgo §5.3).

### 2.6 Pago — riesgo de retroactividad (crítico)

- `ApuestaService.php` L447-462: `premio_posible` se persiste en `detalle_apuestas` al VENDER (informativo).
- `PagoController::calcularPremio` L232-249 → `JuegoPluginManager::calcularPremio` L89 → `PremiosEngine::calcular` L39-65: **el pago se recalcula con el `config.premios` ACTUAL al momento de pagar**. Cambiar premios afecta apuestas ya vendidas y aún no pagadas.
- Venta gateada por `$juego->active`: `ApuestaService.php` L394.

### 2.7 Panel

| Hallazgo | Evidencia |
|---|---|
| Lista juegos, solo lectura de `premio_multiplo` | `panel/src/pages/juegos.astro` L25: `j.config?.premio_multiplo||'-'` |
| Toggle sin body + catch silencioso | `juegos.astro` L31: `apiFetch('PATCH','/juegos/'+id+'/toggle')` sin `{active}` y `catch(err) {}` → el 422 del backend (`JuegoController.php` L39-41) se traga |
| `apiFetch` SÍ maneja errores | `panel/src/utils/api.ts` L21-26 (parsea `message`) — el problema es el `catch` vacío de la página, no el util |
| Nav Juegos solo `super_master\|master` | `panel/src/layouts/AdminLayout.astro` L221 — consistente con el middleware de rutas de escritura |
| Sin UI de premios ni consumo de `/reglas` | grep 0 en `panel/src` |

### 2.8 Tests existentes y faltantes

- Existen: `JuegosJsonTest.php` (catálogo + `reglas` con premios, L464-488), `PremiosOficialesTest.php` (catálogo + `configPara`), `ModalidadesSingleDrawTest.php` (usa `configPara`).
- FALTAN (confirmado en `docs/PENDIENTE.md` L31-33): tests de `JuegoController::update()`, de `JuegoController::toggle()` con auditoría, y del plugin TripleZulia. No existe ningún test de un endpoint de premios.
- Suite: `DB_DATABASE=lotto_test_motor php artisan test` (922 passed / 2 skipped, `docs/motor-premios.md` L211). `composer test` excede el timeout de 300 s (L210).

### 2.9 Docs de referencia

- `docs/motor-premios.md` §9.1 (L189-194): fuente de verdad `config.premios`; hoy sin UI ni endpoint dedicado; `PUT /juegos/{id}` reemplaza array completo (L192); **backlog acordado (Engram #352)**: `PUT /juegos/{juego}/premios` con merge seguro + espejos + auditoría, pertenece al ciclo "configuracion de juegos" (L193).
- `docs/integracion-front-motor-premios.md` §4: **P1** fix toggle (L233), **P2** editor de premios (L235-238: formulario `base`, modalidades clave/valor, comodines tipo/valor/acumulativo, checkbox `vendible`, vista previa JSON, registro de auditoría; NO usar `PUT /juegos/{id}` con `config` parcial — L236), **P3** vencimiento (L240, NO es este ciclo). Checklist L252-255.
- `docs/PENDIENTE.md` §D (L59-61): gaps de límites — fuera de alcance. §E (L63-65): comisiones — ciclo aparte.

### 2.10 Taquilla (contexto de riesgo, sin proponer cambios)

- `taquilla/src/utils/catalogo.ts` L4: `docs/juegos.json` se copia literal a `src/data/juegos.json` (bundled) y `premio_multiplo` se usa como salvaguarda (L127-128). Editar premios exige re-copiar + rebuild de la taquilla para que el front local la vea (aunque `premio_posible` ya lo calcula el backend al vender).

---

## 3. Opciones de diseño

| Opción | Pros | Contras | Complejidad |
|---|---|---|---|
| **A. Endpoint dedicado `PUT /juegos/{juego}/premios`** (backlog #352) | Merge seguro sobre `config` (preserva `scraper`, `modalidades_permitidas`); validación específica del schema de premios; sincroniza espejos en un solo lugar; auditoría con `accion` propio (`'premios'`); no altera el contrato de `PUT /juegos/{id}`; testable de forma aislada | Un endpoint más que mantener; obliga a decidir semántica de reemplazo (atómico vs merge de subclaves) | Media |
| **B. Mejorar el `PUT /juegos/{juego}` existente** | Sin ruta nueva | Cambiaría la semántica actual (reemplazo completo de `config`) — riesgo alto de regresión para cualquier cliente que mande `config` parcial; mezcla `name`+`config` con premios; validación difícil de especializar | Media-Alta |
| **C. PATCH parcial sobre `config` (`PATCH /juegos/{juego}/config` con merge profundo)** | Generaliza el merge para cualquier subclave | Validación genérica débil (exigiría schema por clave); merge profundo de JSON con edge cases (arrays vs objetos); superficie de cambio mayor; scope creep | Alta |

**Recomendación: Opción A** — coincide con el backlog #352 y con `docs/motor-premios.md` §9.1; el merge de espejos ya existe en `PremiosOficiales::configPara()` y debe reutilizarse/extractarse.

Dentro de A, semántica propuesta: **reemplazo atómico de `config.premios`** (el body ES el objeto `premios` completo) con merge de alto nivel sobre `config` (solo se tocan las claves `premios` y sus espejos). El merge por subclave (enviar solo `base`) se descarta en esta exploración: produce estados intermedios difíciles de auditar y validar (pregunta abierta P1).

---

## 4. Contrato JSON propuesto

### 4.1 Endpoint

```
PUT /api/v1/juegos/{juego}/premios
Roles: super_master|master (mismo grupo de rutas que update/toggle, api.php L120-123)
```

### 4.2 Body

```json
{
  "premios": {
    "base": 50,
    "modalidades": {
      "terminal": 60,
      "punta": 60
    },
    "comodines": {
      "patronus-75": {
        "tipo": "numero",
        "premio_multiplo": 120,
        "numero": 75,
        "nombre": "Patronus"
      },
      "patronus-palabra": {
        "tipo": "palabra",
        "premio_multiplo": 20,
        "acumulativo": true,
        "nombre": "PATRONUS"
      }
    }
  }
}
```

### 4.3 Validaciones

| Campo | Regla | Justificación |
|---|---|---|
| `premios` | `required\|array` | — |
| `premios.base` | `required\|integer\|min:1` | Montos > 0 (riesgo §5.1) |
| `premios.modalidades` | `array` (vacío permitido); claves = `string`, valores `integer\|min:1` | — |
| claves de modalidad | deben existir en `plugin->obtenerModalidades()` (fallback: vocabulario canónico de `PremiosOficiales`) | Evita modalidades que el motor paga 0 en silencio (`PremiosEngine.php` L90-92) |
| `premios.comodines` | `array` (vacío permitido); clave = `string` | — |
| `comodines.*.tipo` | `in:flag,letra,numero,palabra` | Coincide con `PremiosEngine::multiplicadorConComodines` L146-151 |
| `comodines.*.premio_multiplo` | `required\|integer\|min:1` | — |
| `comodines.*.acumulativo` | `boolean`; solo válido con `tipo=palabra` | El motor solo suma si `acumulativo` (L149) |
| `comodines.*.numero` / `valor` / `nombre` | según tipo: `numero` para `tipo=numero`, `valor` para `letra`, `nombre` informativo | Espejo del catálogo oficial (L55-69, L191-209) |
| Juego sin `base` (la-ricachona) | 422 con mensaje claro | Sin fuente oficial (REQ7); no tiene premios que editar |

### 4.4 Efectos del endpoint (en orden)

1. Validar schema + permisos (403 para roles fuera de `super_master|master`).
2. Merge de alto nivel: `config = array_merge(config_existente, ['premios' => nuevo])` — preserva `scraper`, `modalidades_permitidas`, etc.
3. Sincronizar espejos (misma lógica que `PremiosOficiales::configPara()`, L336-356): `premio_multiplo` = `base`; `config.modalidades` = espejo legacy (mapa `ESPEJO_MODALIDADES` + `ESPEJO_EXTRA`); `config.comodines` = `premios.comodines`.
4. Auditoría: `JuegoAuditoria::create(['accion' => 'premios', 'cambios' => ['before' => premios_viejo, 'after' => premios_nuevo]])` + `updated_by`.
5. Respuesta: `{premios, premio_multiplo, modalidades, comodines}` (o el juego completo con `pluginJuego`).

### 4.5 Fuera del contrato (a decidir por el cliente, §7)

- `vendible`: hoy `vendible` = `active` (derivado, `JuegoCatalogoService.php` L43). El checkbox `vendible` de P2 (integracion-front §4.2) implicaría columna nueva y semántica independiente de `active` — se propone dejarlo fuera y que el toggle siga controlando la venta (pregunta P3).

---

## 5. Riesgos de producción

1. **RETROACTIVIDAD (crítico)**: el pago recalcula con el `config.premios` vigente al pagar (`PagoController.php` L232-249 → `PremiosEngine::calcular`). Editar premios afecta apuestas vendidas antes del cambio que aún no se pagan. Mitigaciones: confirmación explícita en la UI, registro de auditoría, y documentar la regla de negocio. Un snapshot por sorteo sería otra capa, pero queda fuera de alcance.
2. **Inconsistencia de espejos**: si el endpoint no sincroniza `premio_multiplo`/`modalidades`/`comodines` legacy, el export (`JuegoCatalogoService` L40-45) y los tests legacy quedan con valores viejos.
3. **Contrato `docs/juegos.json`**: `JuegosJsonTest` L80-109 falla si no se re-exporta tras editar premios; y la taquilla bundlea una copia literal (`taquilla/src/data/juegos.json`, `catalogo.ts` L4) → requiere rebuild para ver los cambios. El WU de apply debe incluir re-export + actualización de la copia de la taquilla (coordinación con el otro agente).
4. **Modalidades inválidas** → premio 0 silencioso en `premio_posible` (`PremiosEngine` L90-92) y en liquidación si la clave no matchea. La validación contra el plugin es obligatoria.
5. **Comodines malformados**: el motor ignora comodines no-array (L139-141) y tipos fuera de `flag|letra|numero|palabra`; aceptarlos en el endpoint crea configs que no pagan lo que la UI muestra.
6. **la-ricachona**: no debe ser editable (sin `base`); el endpoint debe rechazarla explícitamente.
7. **Concurrencia**: editar premios mientras hay ventas/pagos en curso — se acepta último-write-wins (sin lock) salvo decisión contraria del cliente; la auditoría da trazabilidad.
8. **Permisos**: confirmar que solo `super_master|master` editan (consistente con rutas actuales y nav del panel).

---

## 6. Alcance mínimo de UI (panel)

1. **P1 — Fix toggle** (`juegos.astro` L28-32): mandar body `{active: !active}` y reemplazar el `catch(err) {}` por render de error visible. Costo mínimo, desbloquea la percepción de "configuración" del panel.
2. **P2 — Editor de premios**: en `juegos.astro` (modal) o página `juegos/[id].astro`:
   - Campo `base` (número).
   - Lista editable de `modalidades` (clave/valor) — claves sugeridas desde `GET /juegos/{id}/reglas` (el plugin ya expone `modalidades` y `premios`).
   - Lista editable de `comodines` (tipo/valor/acumulativo según tipo).
   - Botón Guardar → `PUT /juegos/{id}/premios`; mostrar errores de validación del backend (apiFetch ya los parsea, `api.ts` L21-26).
   - Sección de auditoría (leer `GET /juegos/{id}` → `auditoria[].user`).
3. Nav: sin cambios (ya existe "Juegos" para `super_master|master`, `AdminLayout.astro` L221).

---

## 7. Preguntas abiertas para el cliente

1. **Semántica del body**: ¿reemplazo atómico de `premios` completo (recomendado) o merge por subclave (enviar solo `base` sin tocar modalidades/comodines)?
2. **Retroactividad**: ¿confirmamos que editar premios afecta a apuestas vendidas aún no pagadas (sin snapshot por sorteo)? ¿La UI debe pedir confirmación explícita que lo advierta?
3. **`vendible`**: ¿se mantiene como espejo de `active` (toggle controla venta, sin columna nueva) o entra en alcance desacoplarlo (columna + migración + semántica propia)? Recomendado: mantener espejo.
4. **Claves de modalidad**: ¿la validación contra `obtenerModalidades()` del plugin es suficiente, o hay claves canónicas (p. ej. `signo_*`, `triple_a_b`) que el panel debe permitir aunque el plugin no las liste hoy?
5. **Permisos**: ¿solo `super_master|master` (recomendado, consistente con el resto) o también `banca` para sus juegos?

---

## 8. Estimación por slices (forecast para chained PRs / budget 800 líneas)

Estrategia de entrega: **feature-branch-chain** (preflight). Los slices deben caber en el budget de revisión de 400 líneas por PR y en el budget del cambio (800 líneas).

| Slice | Contenido | Estimación (líneas) | ¿Autónomo? |
|---|---|---|---|
| **S1** | Backend: endpoint `PUT /juegos/{juego}/premios` (validación + merge + espejos + auditoría + ruta) + tests Feature (200/422/403, espejos, auditoría, reflejo en `reglas` y export) | ~450-550 | Sí (el backend queda completo y testeado) |
| **S2** | Fix P1 toggle en panel + tests backend de `toggle()`/`update()` con auditoría (deuda de `PENDIENTE.md` L31-33) + re-export de `docs/juegos.json` | ~120-180 | Sí |
| **S3** | UI editor de premios (P2): form base + modalidades + comodines + guardar + vista auditoría | ~150-250 | Sí (depende de S1 para el endpoint) |

**Total estimado**: ~720-980 líneas → riesgo Medio-Alto sobre el budget de 800; **chained PRs recomendado: Sí** (S1 solo excede los 400 de revisión: puede partirse en S1a endpoint+validación y S1b tests de integración si el corte queda limpio).

Líneas de guard para el forecast:
- `Decision needed before apply: Yes` (preguntas §7 pendientes de respuesta).
- `Chained PRs recommended: Yes` (3 slices, S1 > 400 líneas).
- `400-line budget risk: High` (S1 ~450-550).
- `800-line change budget risk: Medium` (720-980 según cortes).

---

## 9. Notas de proceso

- Artefacto OpenSpec: `openspec/changes/configuracion-juegos/explore.md` (este archivo).
- Engram: `sdd/configuracion-juegos/explore` (topic key, `capture_prompt: false`).
- Skill registry (`/home/gzuz/Documentos/lotto-app/.atl/skill-registry.md`): sin skill específico de PHP/Laravel — esta fase se resolvió con `sdd-explore` + `sdd-phase-common` + `openspec-convention` cargados por instrucción del orquestador.
- Solapamientos notados (NO en alcance): comisiones (`docs/PENDIENTE.md` §E, `integracion-front-motor-premios.md` §5) y gaps de límites (§D); ventana de vencimiento (P3) es otro ciclo; `GET /juegos/{id}/auditoria` (PENDIENTE.md §2) sería un WU natural del slice S3 o posterior.