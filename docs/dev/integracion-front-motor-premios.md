# Integración de los fronts con el motor de premios — contrato y plan de implementación

> **Índice consolidado de pendientes de ambos fronts**: `docs/dev/pendientes-front.md` (fuente única). Este documento conserva el contrato detallado por front.

> **Para**: agentes/devs de `taquilla/` y `panel/`.
> **Backend**: `main` — el motor de premios, los fronts (1.0.0 → 1.0.3) y el sistema de comisiones YA están mergeados y desplegados.
> **Referencias**: `docs/dev/motor-premios.md` (como funciona el motor por dentro) · `docs/juegos.json` (catalogo exportado; version 1) · `openspec/changes/archive/2026-09-26-motor-premios/` (spec verificada) · `docs/cliente/multiplicadores-juegos.md` (valores oficiales).
> **Fecha**: 2026-09-26 · **Actualizado**: 2026-10-06.

---

## 0. Resumen ejecutivo por front

| Front | Cambio mas urgente | Cambios de contrato | Trabajo nuevo |
|---|---|---|---|
| **Taquilla** | **Pago de premios roto**: hoy manda `tipo:'bs'` + sin `moneda` + monto apostado (el backend exige `egreso` + `moneda` + monto = premio). Ademas solo procesa `pendiente`, y las apuestas nuevas quedan en `ganadora` -> **nunca se pagan** | Payload de `combinacion` para modalidades single-draw; estados nuevos `ganadora`/`vencido`; render de resultados con claves nuevas | Catalogo dinamico (hoy 7 juegos hardcodeados; el catalogo tiene 21); seleccion de figuras especiales (Monje 0-75) |
| **Panel** | Toggle de juego con body `{active}` (hoy el PATCH va sin body -> 422 que el `catch` silencioso oculta) | Ninguno obligatorio | Editor de premios por juego (requiere endpoint backend, backlog), pagina de configuracion de vencimiento (endpoint ya existe), badges de estados |

---

## 1. Contrato comun del backend

### 1.1 Estados

**Apuesta** (ENUM final): `pendiente` -> `ganadora` -> `pagada` | `perdida` | `vencido` | `anulada`.

| Transicion | Cuando | Quien la provoca |
|---|---|---|
| `pendiente` -> `ganadora` (+ `resultado_id`) | el sorteo trae el resultado ganador | `verificarGanadores` (automatico) |
| `pendiente` -> `perdida` | el sorteo trae resultado no ganador | `verificarGanadores` (automatico) |
| `pendiente`/`ganadora` -> `pagada` | pago del premio | `POST /pagos` |
| `pendiente` -> `vencido` | resultado nunca llego tras la ventana (24h default) y el catch-up no encontro nada | `MarcarApuestasVencidasJob` (diario 02:00) |
| `pendiente` -> `anulada` | anulacion de ticket | `DELETE /tickets/{id}` (valida `tiempo_eliminacion` real del backend) |

**Ticket**: `pendiente`, `pagada`, `anulada`, `ganador`, `vencido`. Se cierra cuando todas sus apuestas estan resueltas (`vencido` cuenta como resuelta; `ganadora` NO: falta pagar).

> **Gap conocido — tickets sin ganadores**: NO existe estado "perdedor" de ticket. Un ticket cuyas apuestas perdieron todas **queda en `pendiente` de forma permanente** (ni `main` ni la rama lo cambian; la cascada a `pagada` solo ocurre al pagar el ultimo premio, y `ganador` solo se pone si hay premios). Para mostrarlo como "perdida" en la UI: derivar del payload de `GET /tickets` (ya incluye `apuestas.estado` + `apuestas.detalles` + `ganadoras_count`/`tiene_ganadores`) = "ticket resuelto sin ganadores" (sin apuestas `pendiente`/`ganadora` y con `tiene_ganadores=false`). Alternativa: mini-WU backend que exponga `resuelto`/`estado_display`. Verificar ademas que las apuestas pendientes de verdad (sin resultado que coincida en juego + fecha + HORA exacta de sorteo) no se confundan con este caso.

> **Gap de front**: `taquilla/src/pages/historial.astro:91-100` solo conoce `pendiente/pagada/anulada/perdida` (+ badge `ganador` de ticket). `ganadora` cae al `default` sin estilo y `vencido` no existe. Los filtros (`:17-19`) solo ofrecen `pendiente/pagada/anulada`.

### 1.2 Monedas

- Cada linea manda `amount_bs` **o** `amount_usd` (la otra en 0). No hay campo `moneda` por linea.
- El backend valida monedas efectivas de la taquilla (herencia taquilla -> grupo -> banca) y rechaza 422 con mensaje.

### 1.3 Pago de premios — payload exacto (backend AUTORITATIVO)

```json
POST /api/v1/pagos
{
  "apuesta_id": 123,
  "tipo": "egreso",
  "moneda": "bs"
}
```

Ya no hace falta mandar montos: el backend **ya calculo el premio** al liquidar (`premio_ganado` + `premio_total` del ticket) y lo **aplica el mismo** al pagar (`config.premios` via `PremiosEngine`). La taquilla no calcula ni confirma nada.

Reglas del backend (`PagoController`):
- `tipo` in `ingreso|egreso|devolucion` (para premios: **`egreso`**).
- `moneda` in `bs|usd|mixto` (**obligatorio**) — es la moneda en que se paga, no un monto.
- Para `egreso`: la apuesta debe tener `resultado_id` y `estado in {pendiente, ganadora}`.
- `amount_bs`/`amount_usd` son **OPCIONALES**: si se omiten, el backend aplica el premio calculado por el motor; si se envian, DEBEN coincidir (+-0.01) con ese premio (422 con `premio_esperado_bs/usd` y `sugerencia`).
- La respuesta incluye `premio: { premio_bs, premio_usd }` (lo aplicado) para mostrar al cliente.
- Al pagar: apuesta -> `pagada`; cascada del ticket (`premio_total`).

> **Estado actual taquilla (roto)**: `ganadores.astro:144-172` y `historial.astro:217-243` mandan `{apuesta_id, amount_bs, amount_usd, tipo:'bs'}` (monto apostado). Con este contrato el fix es minimo: `tipo:'egreso'` + `moneda` + aceptar `estado === 'ganadora'`; los montos se pueden omitir.

### 1.4 Catalogo de juegos

Fuente: `docs/juegos.json` (exportado con `php artisan juegos:export`) y API:

| Endpoint | Devuelve |
|---|---|
| `GET /api/v1/juegos` | Los 21 juegos (modelo con `config` completo) |
| `GET /api/v1/juegos/{id}/reglas` | Reglas del plugin **+ `premios` ({base, modalidades, comodines})** — aditivo |
| `GET /api/v1/juegos/{id}/opciones` | Opciones reales del zoo del juego |
| `GET /api/v1/juegos/{id}/horarios` | Horarios del juego |

Campos clave del catalogo por juego: `slug`, `nombre`, `tipo`, `premios {base, modalidades, comodines}`, `premio_multiplo` (espejo legacy), `active`, **`vendible`** (false para `la-ricachona`), `horarios`, `opciones[]`.

Regla: **juego con `active=false`/`vendible=false` no se ofrece ni permite vender** (el backend igual lo rechaza en `createApuesta`).

### 1.5 Resultados — claves de `numeros_ganadores` que el motor consume

| Clave | Tipo | Uso |
|---|---|---|
| `nombre_animal` | string | animalito ganador (comparacion con acentos normalizados) |
| `numero` | int | Terminal Activo / figura (99 = Guacharito, 75 = Guacharo/Patronus) |
| `terminal` | int | fallback defensivo de terminales |
| `triple_a`, `triple_b`, `triple_c` | string 3 cifras | triples (y posiciones) |
| `signo` | string | sigla (`ESC`) o label; el front puede mostrar label |
| `arrimao` | string 4 cifras | El Arrejuntado |
| `pegadito` | string 5 cifras | El Arrejuntado |
| `figuras[]` | array | Tripleta: `[{animal|nombre_animal, numero}]` |
| `comodin` | `true` / `"A"` / `"B"` | MEGA (`true` -> 40x) / Selva (`A`=160x, `B`=200x) |
| `comodin_nombre` | string | (si el scraper lo trae) nombre/glosa del comodin |
| `patronus` | string/truthy | palabra PATRONUS presente (+20x acumulativo) |

> **Gap de front**: `taquilla/src/pages/resultados.astro:142-148,178-188` solo interpreta `triple_a|triple_b|triple_c|signo|numero|nombre_animal|color_animal`. Debe agregar `figuras[]`, `comodin`/`comodin_nombre`, `patronus`, `arrimao`, `pegadito`.

---

## 2. Payloads de `combinacion` por modalidad (contrato exacto)

Cada linea del ticket es **un sorteo unico** (`sorteo_hora`) y **un monto propio**. La multi-seleccion del MISMO sorteo viaja en `selecciones[]` (no hay Dupleta ni apuestas multi-sorteo).

### 2.1 Formas simples (una seleccion)

| Modalidad | Juegos | `combinacion` (JSON literal) | Clave canonica |
|---|---|---|---|
| Animalito | Familia Lotto Activo, Monje, Guacharito, Guacharo, Granjita, Chaima, Mega, Selva | `{ "animal": "Delfín" }` | `base` (o comodin) |
| Triple seco | Zulia, Caliente, Zamorano, Tachira, Facil, Arrejuntado | `{ "tipo": "triple_a", "numero": "452" }` (idem `triple_b`) | `triple_a`/`triple_b` |
| Triple + signo | idem | `{ "tipo": "triple_c", "numero": "259", "signo": "Escorpio" }` (label o sigla `ESC`, ambas validas) | `signo_triple` |
| Terminal Activo | `terminal-activo` (plugin Terminales) | `{ "numero": "37" }` | `terminal` |
| Punta | Trio Activo, Chance | `{ "tipo": "punta", "numero": "45" }` (2 primeras del triple) | `punta` |
| Terminal | Trio Activo, Zulia, Caliente, Zamorano, Tachira, Chance, Facil | `{ "tipo": "terminal", "numero": "52" }` (2 ultimas) | `terminal` |
| Una | Zamorano | `{ "tipo": "uña", "numero": "2" }` (ultima cifra) | `una` |
| Aproximacion | Facil | `{ "tipo": "aproximacion", "numero": "51" }` (terminal +-1, sin wrap) | `aproximacion` |
| Signo + terminal | Zulia, Caliente, Chance, Zamorano (`signo_terminal`/`signo_una`) | `{ "tipo": "signo_terminal", "numero": "59", "signo": "LEO" }` | `signo_terminal` |
| Signo solo | Chance | `{ "tipo": "signo_solo", "signo": "LEO" }` | `signo_solo` |
| Arrimao | Arrejuntado | `{ "tipo": "arrimao", "numero": "1825" }` (4 cifras) | `arrimao` |
| Pegadito | Arrejuntado | `{ "tipo": "pegadito", "numero": "10503" }` (5 cifras) | `pegadito` |

Notas:
- Los numeros se pueden mandar sin padding ("7"); el motor normaliza (2/3/4/5 cifras segun modalidad).
- El signo se acepta como label ("Escorpio") o sigla ("ESC") — **hoy la taquilla ya manda label**: correcto.
- La `punta`/`terminal`/`una` busca la posicion en CUALQUIERA de los triples A/B/C del sorteo (con padding).

### 2.2 Multi-seleccion del mismo sorteo (`selecciones[]`)

| Modalidad | Juegos | `combinacion` (JSON literal) | Multiplicadores |
|---|---|---|---|
| **Cruzado** | Chance | `{ "modalidad": "cruzado", "selecciones": [ {"tipo":"punta","numero":"75"}, {"tipo":"punta","numero":"14"} ] }` | ambas puntas -> `cruzado` 3.000x; una -> `cruzado_10` 10x |
| **Par Millonario A+B** | Chance | `{ "modalidad": "triple_a_b", "selecciones": [ {"tipo":"triple_a","numero":"756"}, {"tipo":"triple_b","numero":"146"} ] }` | ambos -> `triple_a_b` 200.000x; uno -> `solo_a_b` 150x |
| **Tripleta** | Cazaloton, Loto Chaima | `{ "modalidad": "tripleta", "selecciones": [ {"animal":"perro"}, {"animal":"gato"}, {"animal":"león"} ] }` | Cazaloton `tripleta` 200x; Chaima `tripleta` 50x |
| ~~Dupleta~~ | — | **RECHAZADA** (no existe en el catalogo; `premioPosible`/liquidacion devuelven 0) | — |

- La clave `modalidad` es **recomendada** en los shapes con `selecciones` (el motor la usa para `premio_posible`; una modalidad declarada que no exista en el catalogo del juego se rechaza con 0). Sin `modalidad`, el motor la deriva de los `tipo` de las selecciones.
- Todas las selecciones comparten el `sorteo_hora` de la linea.

### 2.3 Valores por juego (para mostrar en UI)

NO hardcodear multiplicadores en el front: leer `premios` de `GET /juegos/{id}/reglas` o del catalogo. Tabla oficial completa: `docs/cliente/multiplicadores-juegos.md` y spec archivada (seccion "Valores oficiales"). Ejemplos clave: Monje base 50x (+comodines 70/120/140x), Terminal Activo 60x, Trio Activo 600x, Zulia/Caliente/Zamorano 600x, Tachira 500x, Chance 600x (con 200.000x en Par A+B), Facil 700x, Arrejuntado 40x (Pegadito 60.000x), Guacharito 70x (99 -> 150x), Guacharo 60x (75 -> 120x), Mega 30x (MEGA 40x), Selva 80x (A 160x / B 200x), Cazaloton 30x (Tripleta 200x), Chaima 40x (Tripleta 50x).

---

## 3. TAQUILLA — plan de implementacion

### 3.1 Estado actual (inventario condensado)

| Tema | Hoy | Evidencia |
|---|---|---|
| Catalogo | **Hardcodeado**: 7 juegos en `GAMES_CONFIG` (`dashboard.astro:727-778`); `loadJuegos()` es un stub (`:837-839`) que no llama a la API; no se consume `docs/juegos.json` (0 referencias) | falta vender los otros 14 juegos |
| Multiplicadores | Hardcodeados (`multiplicador:30` etc. `:742-775`) y **sin uso** (campo muerto); valores desactualizados (Monje 30 deberia ser 50; Terminal 20 -> 60; Triples 30 -> 600) | grep `multiplicador`: solo definiciones |
| Zoos | 37 figuras fijas (`defaultAnimales`, `:730-739`); Monje real tiene 77 (0-75); un label erroneo ("Cobra" donde el catalogo dice "Cebra") | `:735` |
| Signos | 12 hardcodeados (`:753-766`), se envian como **label** (`:1190`) | correcto para el backend nuevo |
| Payload lineas | `{juego_id, combinacion, amount_bs|usd, sorteo_hora}` (`:1266-1276`); `combinacion.tipo` solo `triple_a|b|c` | falta single-draw |
| Impresion | Ticket basico: animal/numero/montos/totales; **sin** premio posible, estado ni sorteo por linea (`:1280-1288`; render en `electron/main/ipcHandlers.cjs:16-55`) | mejora deseable |
| Pago | **Roto** contra el backend nuevo (seccion 1.3) | `ganadores.astro:144-172`, `historial.astro:217-243` |
| Estados | No conoce `ganadora` ni `vencido` | `historial.astro:91-100` |
| Resultados | Solo claves legacy | `resultados.astro:142-148,178-188` |
| Anulacion | 5 min hardcodeados en cliente (`historial.astro:102-113`); el backend valida con `tiempo_eliminacion` real (el rechazo llega como error) | UX |
| Limites | No maneja nada (solo muestra el 422 del backend) | 0 codigo |
| Dupleta | No existe (correcto: queda asi) | grep 0 |
| Cierre | Placeholder (`cierre.astro:8-12`) | fuera de alcance |

### 3.2 Cambios requeridos

**T1. Catalogo dinamico (reemplaza `GAMES_CONFIG`)** — prioridad alta.
- Fuente: `GET /api/v1/juegos` + `GET /juegos/{id}/opciones` + `/horarios` (o el JSON exportado si se necesita offline; entonces copiarlo al build).
- Render: `renderJuegos` (`:871-886`), `getGameById/getGameType` (`:780-795`) y `loadTripleConfig/loadAnimalesConfig/loadHorariosConfig` (`:916-955`) deben alimentarse del contrato, no de constantes.
- Filtrar `active && vendible`; zoos desde `opciones` (Monje incluira 0-75 + Patronus 75); eliminar los campos `multiplicador` muertos y la dependencia de valores locales.
- Punto de entrada existente a reemplazar: `loadJuegos()` (`:837-839`).

**T2. Payloads de apuesta por modalidad** (seccion 2) — prioridad alta.
- La construccion actual vive en `dashboard.astro:1266-1276`; extender el armado por tipo:
  - animalitos -> `{animal}` (como hoy);
  - tripletas -> `{tipo, numero, signo?}` (como hoy) **mas** las nuevas (`punta`, `terminal`, `una`, `aproximacion`, `signo_terminal`, `signo_solo`);
  - arrejuntado -> `{tipo:'arrimao'|'pegadito', numero}`;
  - chance -> `{modalidad, selecciones[]}` para Cruzado / Par A+B;
  - cazaloton/chaima -> `{modalidad:'tripleta', selecciones[]}`.
- Las opciones de modalidad por juego deben salir del catalogo (`/reglas` ya expone `premios` y el plugin sus `modalidades`), con digito/validacion local (`3 cifras`, `2 cifras`, `4`, `5`, `+-1` para aproximacion).

**T3. Pago de premios (fix critico)** — seccion 1.3.
- Payload minimo: `{ apuesta_id, tipo:'egreso', moneda }` (montos OPCIONALES: el backend aplica el premio del motor).
- Aceptar `estado === 'ganadora'` ademas de `pendiente`.
- Quitar el "exito" falso cuando no se pago nada (`historial.astro:241-242`, `ganadores.astro:167`: mostrar el resultado real del POST).

**T4. Estados y filtros**.
- Agregar badges y filtros `ganadora` y `vencido` (`historial.astro:91-100,17-19`).
- Recordatorio: un ticket con apuestas `ganadora` pendientes de pago NO esta cerrado.

**T5. Resultados** — renderizar claves nuevas (seccion 1.5), con badge de comodin (`comodin`/`comodin_nombre`) y `patronus`.

**T6. Ticket impreso (mejora)**.
- `POST /tickets` HOY responde `data` con `apuestas.juego` (`TicketController.php:187-190`), **sin** el detalle: `premio_posible` se persiste (`detalle_apuestas.premio_posible(_usd)`) pero no viaja en esa respuesta. Opciones: (a) calcular el premio posible en el front desde el catalogo; (b) pedir un mini-WU backend que cargue `apuestas.detalle` en la respuesta. Idem para `premio_total_bs/usd` (si viaja en el ticket).
- Render sugerido: glosa de modalidad, `sorteo_hora` por linea y premio posible.

**T7. Anulacion**.
- Dejar de asumir 5 min (`historial.astro:102-113`): el backend decide con `getEffectiveTiempoEliminacion` (taquilla -> grupo -> banca). Mientras no exista endpoint que exponga el valor, manejar el rechazo del `DELETE` y mostrar el mensaje del backend.

**T8. NO hacer**.
- No implementar Dupleta. No llamar `/limites` (los limites se aplican server-side). No gestionar comisiones en la taquilla: se configuran en panel > Limites y se liquidan/pagan en `/comisiones`.

### 3.3 Checklist de aceptacion (taquilla)

- [ ] Los 21 juegos activos listados desde el catalogo; `la-ricachona` no aparece (o aparece deshabilitada).
- [ ] Monje ofrece figuras 0-75 (incluida Patronus 75); zoos sin labels erroneos.
- [ ] Cada modalidad de la seccion 2 se puede crear y el backend la acepta (201) con `premio_posible > 0`.
- [ ] Cruzado / Par A+B / Tripleta viajan con `selecciones[]` + `modalidad`.
- [ ] Pago de una apuesta `ganadora` funciona end-to-end (`POST /pagos` con `tipo:'egreso'`, `moneda`, monto = premio).
- [ ] Historial muestra `ganadora` y `vencido` con badge y filtro.
- [ ] Resultados renderizan `figuras[]`, `comodin`, `patronus`, `arrimao`, `pegadito`.
- [ ] Ticket impreso incluye (si se implementa T6) premio posible/estado/glosa.

---

## 4. PANEL — plan de implementacion

### 4.1 Estado actual (inventario condensado)

| Tema | Hoy | Evidencia |
|---|---|---|
| Juegos | Lista 21 juegos; muestra `config.premio_multiplo` (solo lectura); unica mutacion: toggle | `panel/src/pages/juegos.astro:21-31` |
| Toggle | `PATCH /juegos/{id}/toggle` **sin body** + `catch` silencioso; el backend exige `{active: bool}` -> 422 tragado | `juegos.astro:31` vs `JuegoController.php:37-41` |
| Premios por juego | Sin UI (ni `premios`, ni `vendible`) | — |
| Configuraciones | No existe pagina; no consume `/configuraciones/apuestas-vencimiento` (backend implementado) | grep 0 |
| Limites | UI completa en `/limites` y tabs de banca/grupo/taquilla (campos `limite_minimo/maximo`, `porcentaje_pago`, `participacion`, `fraccion`, `limite_tiempo`) | `utils/limites.ts:46-53` |
| Comisiones | Implementado: página `/comisiones` (liquidar/pagar), config en Limites, **topes por tipo** (animalitos 16% / tripletas 25%), default global ≤16% | PR #50 · PRs #61/#62 · `openspec/specs/comisiones/spec.md` |
| Estados de apuesta | Sin vista (dashboard rotula "Ganadores Hoy" = `pagada_count`) | `dashboard.astro:52-53` |

### 4.2 Cambios requeridos

**P1. Fix toggle (inmediato)**: mandar body `{ "active": !actual }` y quitar el `catch` vacio (mostrar error). Nota: si el body nuevo no cambia nada, revisar la respuesta real del backend.

**P2. Editor de premios por juego (depende de backend — backlog #352)**.
- Necesita endpoint dedicado `PUT /juegos/{juego}/premios` (merge seguro + sincronizacion de espejos `premio_multiplo`/`modalidades`/`comodines` + auditoria `JuegoAuditoria`). **No usar `PUT /juegos/{id}` con `config` completo**: reemplaza el array entero y puede borrar `scraper`, espejos, etc.
- UI propuesta: formulario `base`, lista de `modalidades` (clave/valor), lista de `comodines` (tipo/valor/acumulativo), checkbox `vendible`, vista previa del JSON y registro de auditoria.
- Este trabajo es alcance del ciclo **"configuracion de juegos"** (siguiente ciclo), no del motor.

**P3. Pagina de configuracion — apuestas**: consumir `GET/PUT /api/v1/configuraciones/apuestas-vencimiento` con `{horas}` entero 1..8760 (default 24). Solo `super_master|master` (403 para el resto). Ubicacion sugerida: nueva pagina `configuraciones.astro` + entrada en `AdminLayout`.

**P4. Estados**: si se agregan vistas de apuestas/ganadores, usar los estados de la seccion 1.1. Revisar el rotulo "Ganadores Hoy" del dashboard (hoy cuenta pagadas, no ganadoras).

**P5. Limites (gaps conocidos, fuera del motor — no bloquean)**:
- No hay DELETE para limpiar/volver a heredar un limite (`utils/limites.ts:216-248`; el comentario de diseño menciona DELETE por fila).
- `limites.astro:92` habilita guardar a `grupo`, pero la API solo acepta escritura de `super_master|master|banca` -> 403 para grupo.
- `GET /limites/{juego}` acepta `agencia_id` pero `juego_limites` no tiene esa columna (filtro roto; no usar desde el panel).
- `components/LimitesTable.astro` no se importa (codigo muerto); `ROLES` de `utils/api.ts:42` sin uso.

### 4.3 Checklist de aceptacion (panel)

- [ ] Toggle de juego funciona (badge cambia y persiste).
- [ ] (Cuando exista P2) editar `premios.base` de un juego se refleja en `GET /juegos/{id}/reglas` y en `docs/juegos.json` tras el export.
- [ ] Pagina de configuracion permite ver/editar la ventana de vencimiento; 403 para roles no autorizados.
- [ ] Ningun cambio del panel toca `PUT /juegos/{id}` con `config` parcial.

---

## 5. Comisiones — estado actual (actualizado 2026-10-06)

El sistema de comisiones **está implementado y operativo** (PR #50) con **tope por tipo de juego** (PRs #61/#62). Spec canónico: `openspec/specs/comisiones/spec.md`.

| Pieza | Estado |
|---|---|
| Liquidación | `ComisionService::liquidar` — manual vía `POST /comisiones/liquidar {desde, hasta, banca_ids?}`; una fila por (nivel, entidad, rango) en estado `pendiente`; solape de período → 422 |
| Tasas | Cascada `taquilla → grupo → banca → default global` sobre `juego_limites.porcentaje_pago`; **topes por tipo**: animalitos **16%**, tripletas **25%**, resto 100% (por nivel); guard suma cero ≤100% como red de seguridad; config legacy se clampa al liquidar |
| Config | Matriz de límites (SM/M/banca/grupo según scope) + default global por moneda (`GET/PUT /comisiones/defaults`, ≤16%) |
| Pago | Página `/comisiones` (SM/M): liquidar, filtrar, `PATCH /comisiones/{id}/pagar` (idempotente, auditado) |
| Integración | Cierre de caja (`comision_bs_equivalent`, diario/semanal) y reportes de ventas/cuadre |
| Permiso | `manage_comisiones` (super_master/master) |

**Fuera del modelo actual** (hilos abiertos, no bloquean): previsualización antes de liquidar, reverso/corrección/pago parcial, filtros server-side del ledger, visibilidad del ledger para banca/grupo, liquidación automática por período, semántica de `participacion` (sigue sin lectores de negocio).

---

## 6. Anexos

### 6.1 Endpoints por front

| Front | Endpoints |
|---|---|
| Taquilla | `POST /tickets`, `GET /tickets`, `GET /tickets/{id}`, `DELETE /tickets/{id}`, `GET /tickets/ganadores`, `POST /pagos`, `GET /resultados`, `GET /exchange-rate/active`, `POST /dispositivo/verificar`, `POST /activar`, `POST /login`, `POST /logout`, `GET /update-check`, `GET /juegos*` (nuevo) |
| Panel | `GET/PUT/DELETE /bancas|/grupos|/taquillas|/agencias|/users`, `GET /juegos`, `PATCH /juegos/{id}/toggle`, `GET/PUT /limites*`, `GET /resultados*`, `GET /configuraciones/apuestas-vencimiento` (nuevo), `GET /reportes/*`, `GET /estadisticas/rendimiento`, `GET /exchange-rates`, `GET /logs`, `GET /releases/*` |

### 6.2 Ejemplo end-to-end de ticket (2 lineas, una con modalidad nueva)

```json
POST /api/v1/tickets
{
  "lines": [
    {
      "juego_id": 21,
      "combinacion": { "modalidad": "cruzado", "selecciones": [
        { "tipo": "punta", "numero": "75" },
        { "tipo": "punta", "numero": "14" }
      ]},
      "amount_bs": 10,
      "amount_usd": 0,
      "sorteo_hora": "2026-09-27 12:45:00"
    },
    {
      "juego_id": 1,
      "combinacion": { "animal": "Delfín" },
      "amount_bs": 0,
      "amount_usd": 1,
      "sorteo_hora": "2026-09-27 13:00:00"
    }
  ]
}
```

### 6.3 Mapa "payload actual taquilla -> requerido"

| Caso | Hoy manda | Debe mandar |
|---|---|---|
| Animalito | `{animal, numero}` | `{animal}` (numero opcional) |
| Triple A/B | `{tipo:'triple_a', numero:'452'}` | sin cambio |
| Triple C | `{tipo:'triple_c', numero:'259', signo:'Escorpio'}` | sin cambio (label OK) |
| Terminal Activo | `{numero:'37'}` | sin cambio |
| Cruzado Chance | ❌ no existe | `{modalidad:'cruzado', selecciones:[{tipo:'punta',numero},...2]}` |
| Par A+B Chance | ❌ no existe | `{modalidad:'triple_a_b', selecciones:[{tipo:'triple_a',numero},{tipo:'triple_b',numero}]}` |
| Tripleta (Cazaloton/Chaima) | ❌ no existe | `{modalidad:'tripleta', selecciones:[{animal},{animal},{animal}]}` |
| Arrimao / Pegadito | ❌ no existe | `{tipo:'arrimao'|'pegadito', numero:'NNNN(N)'}` |
| Punta / Una / Aproximacion / Signos | ❌ no existe | ver seccion 2.1 |
| Pago | `{apuesta_id, amount_bs, amount_usd, tipo:'bs'}` (roto) | `{apuesta_id, tipo:'egreso', moneda}` — montos opcionales (el backend aplica el premio) |

### 6.4 Orden de trabajo sugerido

1. **Taquilla T3** (pago) y **Panel P1** (toggle): fixes criticos de bajo costo.
2. **Taquilla T1** (catalogo) -> habilita vender los 21 juegos.
3. **Taquilla T2 + T4 + T5** (modalidades, estados, resultados).
4. **Panel P3** (vencimiento) y **P2** (editor de premios, cuando el ciclo "configuracion de juegos" entregue el endpoint).
5. **T6/T7** (mejoras de ticket y anulacion) segun prioridad.
