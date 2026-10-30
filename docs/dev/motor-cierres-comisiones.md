# Motor de cierres de caja y comisiones

> Cómo funciona el bloque de negocio de **cierres de caja** y **comisiones**:
> modelo de dominio, flujo de un cierre, el motor de tasas, la estructura de
> datos y los reportes asociados.
>
> Los pendientes viven en [`pendientes-front.md`](pendientes-front.md).
> Para el motor de premios ver [`motor-premios.md`](motor-premios.md).

## 1. Mapa mental: un solo subsistema de comisiones

Las comisiones son **consultivas**: se calculan y se leen, no se liquidan ni se
pagan. Existe un único motor de cálculo con dos superficies de lectura.

| Superficie | Dónde vive | Cuándo se calcula | Quién lo dispara |
|---|---|---|---|
| **Snapshot del cierre** | `cierres_caja.comision_bs_equivalent` | Al cerrar caja | `CierreService` |
| **Consulta por rango** | Respuesta de `GET /comisiones/consulta` (no persiste) | Bajo demanda | `ComisionController::consulta` → `ComisionService::filasPorRango` |

El **motor de cálculo** (tasas, topes) lo comparten el snapshot, la consulta y
los reportes. La definición de tasas vive en `juego_limites.porcentaje_pago`
(con herencia taquilla → grupo → banca → default global), **no** en una tabla de
comisiones. `ComisionDefault` es solo el fallback global por moneda.

La tabla histórica `comisiones` se archivó en `comisiones_archivo_2026` y se
eliminó: hoy no es un store activo, no hay transición `pendiente → pagado` ni
flujo de pago. El archivo conserva la auditoría de las filas históricas.

## 2. Archivos que participan

### Servicios

| Archivo | Responsabilidad |
|---|---|
| `app/Services/CierreService.php` | Núcleo del cierre diario por taquilla: período, agregados de ventas/egresos, desglose por método, arqueo/faltante-sobrante, snapshot de comisión y re-cierre con clave |
| `app/Services/ComisionService.php` | Motor de tasas (cascada, topes) y cálculo consultivo por rango (`filasPorRango`). No escribe en BD |
| `app/Services/ConfiguracionService.php` | Settings globales (`configuraciones`). Hoy solo la ventana de vencimiento de apuestas; **no interviene en cierres ni comisiones** |
| `app/Services/ActivacionEfectivaService.php` | "Activo efectivo" de la jerarquía. **No lo usan cierres ni comisiones** |

### Controladores

| Archivo | Responsabilidad |
|---|---|
| `app/Http/Controllers/Api/CierreController.php` | HTTP del cierre + toda la autorización jerárquica de alcance |
| `app/Http/Controllers/Api/ComisionController.php` | Defaults globales (`defaults`, `updateDefaults`) y consulta read-only por rango (`consulta`) |
| `app/Http/Controllers/Api/ClaveCierreController.php` | Self-service de la clave de cierre (PIN 4–8 dígitos, hasheada) |
| `app/Http/Controllers/Api/PagoController.php` | Registro de pagos (ingreso/egreso/devolución); en `egreso` el backend recalcula el premio con el motor de premios |
| `app/Http/Controllers/Api/ReporteController.php` | Fachada de reportes; delega la agregación en `ApuestaService` |

### Modelos

`CierreCaja`, `ComisionDefault`, `Pago`, `Ticket`, `Apuesta`,
`Taquilla`, `Grupo`, `Banca`, `Agencia`, `Configuracion`, `User`.

## 3. Modelo de dominio

```
super_master (global)
   └── master (super banca)  ──administra──►  Banca (bancas.master_id = users.id)
                                                   └── Grupo (grupos.banca_id)
                                                          ├── Agencia (agencias.grupo_id)   ← local físico
                                                          └── Taquilla (taquillas.grupo_id)
                                                                  └── Agencia (taquillas.agencia_id, nullable)
```

- **Banca → Grupo → Taquilla** es la cadena canónica de negocio.
- **Agencia** es un nivel *passthrough* (local físico): cuelga del grupo y las
  taquillas apuntan opcionalmente a él. **No configura límites, monedas, vigencia
  ni comisión propia.** No cobra comisión; su valor en reportes es un *rollup*
  informativo (`Σ grupo + Σ taquilla`).
- **User** se ancla a un nivel por `role` + columna foránea (`banca_id` /
  `grupo_id` / `taquilla_id` / `agencia_id`). El `master` administra bancas vía
  `bancas.master_id`.

### Roles y alcance

| Rol | Ve cierres | Puede cerrar | Consulta de comisiones |
|---|---|---|---|
| `super_master` | todo | cualquier taquilla | todo el rango |
| `master` | sus bancas (`bancas.master_id`) | taquillas de sus bancas | solo sus bancas |
| `banca` | taquillas de su banca | taquillas de su banca | no (403) |
| `grupo` | taquillas de su grupo | taquillas de su grupo | no |
| `agencia` | taquillas de su local | taquillas de su local | no |
| `taquilla` | solo la suya | su propia caja | no |

## 4. Flujo completo de un cierre de caja

### 4.1 Apertura

No hay "apertura": el cierre es un acto atómico sobre un rango que **continúa
desde el último cierre** (`resolveFechaInicio`: último `fecha_fin` → primera
`fecha_hora` de apuesta → `now()`).

Rol `taquilla` cierra su propia taquilla; roles administrativos deben enviar
`taquilla_id`, validado contra su alcance.

### 4.2 Cálculo del período (`CierreService::crearCierre`)

Dentro de `DB::transaction`:
1. `resolverTasa()`: tasa activa; si no, la última por `reference_date`; si no hay ninguna → 422.
2. `resolveCierreHoy()`: busca un cierre con `fecha_fin` dentro del día (Caracas).
3. `calcularTotales()` sobre `[fechaInicio, fechaFin)`.

### 4.3 Qué se calcula (`calcularTotales`)

- **Ventas** (`apuestas`): `SUM(amount_bs)`, `SUM(amount_usd)`, `SUM(total_bs_equivalent)`, excluyendo `estado='anulada'` y soft-deleted.
- **Desglose de ventas**: join a `pagos` de esas apuestas con `tipo='ingreso'`, agrupado por `metodo_pago`.
- **Egresos** (`pagos`): `tipo IN ('egreso','devolucion')` por `created_at`.
- **Efectivo** = ventas − egresos, por moneda.
- **`desglose_metodos`**: shape `{bs|usd: {efectivo|transferencia|pago_movil|punto_venta: {ventas, egresos, efectivo}}}`. Regla AD-4: **todo el USD se normaliza a `efectivo`**.
- **`comision_bs_equivalent`**: snapshot de la comisión del período (`comisionCadena`).

### 4.4 Arqueo y faltante/sobrante

- `arqueo_efectivo_bs/usd` = lo contado (opcional).
- `faltante_sobrante_bs/usd` = `round(arqueo − total_efectivo, 2)`, o `null` sin arqueo. Negativo = faltante, positivo = sobrante.
- `exchange_rate_cierre` = tasa usada.

### 4.5 Re-cierre del día y `clave_cierre`

Si ya existe un cierre del día, se exige `clave_cierre` y se valida
(`validarClaveCierre`) contra los candidatos de la cadena: usuarios `role='banca'`
de la banca del grupo, el `master` de la banca, y todos los `super_master`
(`Hash::check` en OR).

Actualiza **la misma fila**: recalcula desde el `fecha_inicio` original, extiende
`fecha_fin = now()`, refresca totales/arqueo/desglose/comisión y registra
`reclosed_by` / `reclosed_at`. `fecha_inicio` y `created_by` no se tocan.
La clave nunca se persiste en claro ni se expone (`User::$hidden`).

### 4.6 Auditoría

- Re-cierre: columnas `reclosed_by`, `reclosed_at`.
- `Log::create` con `action` `pago_premio`. Las acciones `comision_liquidar` /
  `comision_pagar` ya no se generan (el ledger se retiró); las filas históricas
  de `logs` se conservan como auditoría.
- Nota: **el primer cierre no genera Log** (solo `created_by`).

### 4.7 Preview y semanal

- `GET /cierre/actual` → `previsualizar()`: mismos cálculos sin persistir; indica si hay cierre hoy.
- `GET /cierre/semanal` → `reporteSemanal()`: agrega las filas persistidas del rango, fusiona desglose, suma comisión y arqueo.

## 5. Motor de comisiones a fondo

### 5.1 Dónde se define

- **`comision_defaults`**: fallback global por moneda (`bs`, `usd`), `porcentaje_pago` nullable.
- **`juego_limites.porcentaje_pago`**: la definición real, por `(juego, moneda, banca|grupo|taquilla)`. La misma tabla guarda los límites min/max de venta.

> `ComisionDefault` **no** es "la comisión por defecto de una banca"; es un único default global del sistema. No hay defaults por entidad.

### 5.2 A quién aplica

- **Cobran**: banca, grupo, taquilla. **Agencia NO** (rollup informativo).
- **Cascada de tasa efectiva**: `taquilla > grupo > banca > default global`. Una fila con `porcentaje_pago = NULL` cede al siguiente nivel.

### 5.3 Fórmula exacta

Para cada `(entidad, juego, moneda)`:

```
tasaEfectiva   = primera fila no-nula en la cascada, o defaultGlobal(moneda)
sumaAncestros  = Σ tasas PROPIAS de ancestros (banca para grupo; grupo+banca para taquilla)
topeTipo       = topePorTipo(juego.type)   // animalitos 16, tripletas 25, resto 100
tasaLiquidable = min(tasaEfectiva, max(0, 100 − sumaAncestros), topeTipo)

buckets:    bs        = SUM(apuestas.amount_bs)                            // bs-only + mixtas
            usd_equiv = SUM(apuestas.amount_usd × exchange_rate_applied)   // usd-only + mixtas
comision = bs × (tasaLiquidable_bs/100) + usd_equiv × (tasaLiquidable_usd/100)
```

Un solo `round(…, 2)` al final por entidad. Solo `estado != 'anulada'`.

### 5.4 Cuándo se calcula

- **Snapshot del cierre**: en `crearCierre` / `previsualizar` (`comisionCadena`), persistido en `cierres_caja.comision_bs_equivalent`.
- **Consulta**: solo cuando un `super_master`/`master` llama `GET /comisiones/consulta`. **No hay job ni generación automática**, y la consulta **no persiste nada**: no escribe filas de comisión ni registra acciones de liquidación/pago.

### 5.5 Cómo se consulta (sin flujo de pago)

`GET /comisiones/consulta?desde=YYYY-MM-DD&hasta=YYYY-MM-DD[&banca_ids[]=…]`:
solo `super_master|master` (403 para el resto), el master limitado a sus bancas
(`banca_ids` fuera de alcance → 403). Devuelve
`{data: [{nivel, entidad_id, entidad, monto_comision}], meta: {desde, hasta, total}}`
con `monto_comision` en bs-equivalente y 2 decimales; las entidades sin ventas se
omiten. El rango es inclusivo y se normaliza a día completo
(`startOfDay`/`endOfDay`). **No existe marcar pagada, reverso, pago parcial ni
estado por fila.** El snapshot de `cierres_caja` sigue siendo la lectura del
cierre; la consulta es la lectura por entidad.

### 5.6 Reparto en la jerarquía

**No es un reparto en cascada de un monto único**, sino **una fila independiente
por nivel** con su propia `tasaLiquidable` (topada por los ancestros). Ejemplo
del test: banca propia 10 + grupo propio 20 + taquilla propia 100 ⇒ liquidables
10 / 20 / 70 (suma 100).

En reportes: grupo muestra *su* liquidable, banca *el suyo*, y **agencia** el
rollup `Σ grupo + Σ taquilla` de su subárbol.

## 6. Estructura de datos

### `cierres_caja`

`taquilla_id` (FK cascade), `fecha_inicio` / `fecha_fin` (timestamp),
`total_ventas_bs/usd`, `total_ventas_bs_equivalent`, `total_egresos_bs/usd`,
`total_efectivo_bs/usd`, `exchange_rate_cierre`, `created_by`,
`arqueo_efectivo_bs/usd`, `faltante_sobrante_bs/usd`, `desglose_metodos` (json),
`comision_bs_equivalent`, `reclosed_by`, `reclosed_at`.

### `comisiones_archivo_2026`

Archivo histórico de la extinta tabla `comisiones` (sin FKs ni índices únicos;
índices por columna y uno de rango). `id` preservado, `banca_id` / `grupo_id` /
`taquilla_id` (nullable, solo uno seteado), `periodo`
(`YYYY-MM-DD..YYYY-MM-DD`), `monto_comision`, `estado` (`pendiente`|`pagado`,
histórico), `fecha_inicio` / `fecha_fin`, `archivado_at`. **No es un store
activo**: ninguna ruta la escribe; se conserva para auditoría y se restaura
desde el dump si hiciera falta.

La consulta (`GET /comisiones/consulta`) no tiene tabla propia: se calcula al
vuelo desde `apuestas`, `juego_limites` y `comision_defaults`.

### `comision_defaults`

`moneda` enum(`bs`,`usd`) unique, `porcentaje_pago` nullable.

### `pagos`

`taquilla_id`, `apuesta_id` (nullable), `amount_bs/usd`,
`exchange_rate_applied`, `tipo` (`ingreso`|`egreso`|`devolucion`), `moneda`
(`bs`|`usd`|`mixto`), `metodo_pago` nullable, `concepto`, `referencia`,
`created_by`.

### `juego_limites`

`juego_id`, `banca_id`, `grupo_id`, `taquilla_id`, `moneda`,
`limite_minimo/maximo`, `porcentaje_pago`, `participacion`, `fraccion`,
`limite_tiempo`. Índice único con COALESCE por nivel.

## 7. Reportes

Todos reciben un query **pre-escalado por rol** desde `ReporteController`.

| Endpoint | Calcula |
|---|---|
| `GET /reportes/cuadre-caja` | Agrupa por nivel (`banca|grupo|agencia|taquilla`): `Venta, Pagados, Devoluciones, Vencidos, Efectivo, PesoVenta(%), Participacion(%), Comision`. `Efectivo = Venta − Pagados − Devoluciones − Vencidos` |
| `GET /reportes/ventas-totales` | `Entidad, Venta, Premio, Porcentaje, Utilidad, Participación, Total, Comision` |
| `GET /reportes/relacion-tickets` | Por ticket: `Ticket_N, Agencia, Taquilla, Usuario, Fecha, Monto, Premio, Sorteos, Jugadas, Tipo` |
| `GET /reportes/vencidos` | Tickets `estado='vencido'`, con `withTrashed` para conservar labels históricos |

## 8. Tests existentes

| Test | Garantiza |
|---|---|
| `CierreCajaTest` | Agregados por período, continuidad, fallback de tasa, arqueo, desglose (incl. USD→efectivo), jerarquía y alcance, semanal |
| `ComisionCierreTest` | Snapshot de comisión en el cierre (suma cero 10+20+70=100), exclusión de anuladas, preview no persiste. **Solo jerarquía de UNA taquilla** |
| `ComisionesApiTest` | Defaults (200/403/422, topes 16–17), consulta por rango (3 niveles, rango vacío, alcance `super_master`/`master`, `banca_ids` fuera de alcance → 403, roles sin acceso → 403, 422 de fechas, sin persistencia), matriz de límites. Tests del ledger eliminados |
| `ComisionServiceTest` | Motor de tasas (cascada, topes por tipo, suma cero) y `filasPorRango` (3 niveles, rango vacío, filtro `banca_ids`, base 0 omitida, redondeo 2 decimales) |
| `ComisionReporteTest` | Columna `Comision` en reportes (D11, topes, agencia rollup) |
| `ClaveCierreTest` | Hash de clave, self-service, formato PIN, candidatos válidos |
| `CuadreCajaReportTest` | Fórmula de `Efectivo`, alcance, niveles, filtros de moneda |

## 9. Hallazgos / candidatos a issue

> Documentados aquí como referencia del riesgo. No son un tracker paralelo:
> si se aceptan, se registran en `pendientes-front.md` §7 o se abren como issue.

### Alta

1. **`CierreService::comisionCadena` sobre-cuenta con 2+ taquillas por grupo/banca.** Calcula `comisionEntidad('grupo'|'banca', …)` que suma las ventas de **todo el subárbol**, no solo la taquilla que cierra. Con una sola taquilla coincide por casualidad; con dos o más, el snapshot incluye ventas de las hermanas y `reporteSemanal` lo propaga. No hay test con 2+ taquillas.
2. **`PagoController::store` marca la apuesta como `pagada` para cualquier `tipo`.** La línea de `estado='pagada'` corre también para `ingreso` y `devolucion` (y actualiza `premio_ganado`). Una devolución sobre una apuesta `vencido`/`perdida` la vuelve `pagada`, afectando `cuadre-caja`.

### Media

3. **La tasa de comisión se normaliza a día completo, desincronizada del período del cierre.** `bucketsPorEntidad` fuerza `startOfDay`/`endOfDay` mientras `calcularTotales` usa la ventana exacta `[inicio, fin)`. Con el ledger retirado esto solo afecta al snapshot y a la consulta, no a un rango persistido.

### Baja

4. **`updateDefaults` limita `porcentaje_pago` a `max:16`** aunque los topes reales por tipo son 16/25/100.
5. **Verificación de rol inconsistente**: `CierreController` usa `hasRole()` (Spatie) y `ComisionController`/`ReporteController` usan la columna `$user->role`. Si divergen, la autorización diverge.
6. **El desglose (desde `pagos`) puede no cuadrar con el total de ventas (desde `apuestas`)** si falta el pago ingreso. Sin aserción en runtime.
7. **`Vencidos` se cuenta dos veces por diseño** en `cuadreCaja` (dentro de `Venta` y restado en `Efectivo`); correcto pero sin comentario.
