# Guía del editor de premios (panel) — base, modalidades y comodines

> Para operadores con rol **super_master | master**. Llega con el ciclo `configuracion-juegos` (2026-09).
> **Recordatorio clave**: los cambios **NO son retroactivos** — cada apuesta conserva el premio vigente **al momento de su venta** (snapshot). Lo ya vendido no cambia; las ventas NUEVAS usan los valores nuevos.

## Dónde está y cómo se usa

1. Panel → **Juegos** → clic en la fila del juego → se abre el **editor de premios**.
2. Editás `base`, `modalidades` y `comodines` → **Guardar**.
3. El backend valida campo por campo (si algo está mal, el error aparece junto al campo), **sincroniza los espejos** (tabla del panel / export del catálogo) y deja todo en la **auditoría** (before/after, con tu usuario).
4. `Vendible` se muestra como **espejo de `Activo`** (solo lectura): activar/desactivar el juego sigue siendo la palanca.

## Las 3 secciones de `premios`

| Sección | Qué es | Ejemplo |
|---|---|---|
| **Base** | El multiplicador del acierto "normal" del juego | Monje `50` ⇒ Bs 10 → Bs 500 |
| **Modalidades** | Formas de ganar **alternativas** (cada una con su multiplicador). El cliente apuesta esa modalidad explícitamente | Trio Activo `punta` ⇒ Bs 10 → Bs 600 |
| **Comodines** | Ajustes que se activan cuando **el RESULTADO del sorteo** trae una señal especial (no es una apuesta: modifica el premio de quien acertó) | MEGA ⇒ paga 40× en vez de 30× |

## Modalidades — claves por juego (valores oficiales actuales)

| Juego | Claves de modalidad (multiplicador) |
|---|---|
| Trio Activo | `punta` (2 primeras cifras) 60 · `terminal` (2 últimas) 60 |
| Triple Zulia | `terminal` 60 · `signo_triple` (triple C exacto + signo) 6000 · `signo_terminal` 600 |
| Triple Caliente | `terminal` 60 · `signo_triple` 6000 · `signo_terminal` 600 |
| Triple Zamorano | `terminal` 60 · `una` (última cifra) 5 · `signo_triple` 6000 · `signo_terminal` 600 · `signo_una` 60 |
| Triple Táchira | `terminal` 50 · `signo_triple` 5000 |
| Triple Chance | `cruzado` (2 puntas, ambas) 3000 · `cruzado_10` (una punta) 10 · `triple_a_b` (Par A+B exactos) 200000 · `solo_a_b` (uno de los dos) 150 · `punta`/`terminal` 60 · `signo_triple` 6000 · `signo_terminal` 600 · `signo_solo` 6 |
| Triple Fácil | `terminal` 60 · `aproximacion` (terminal ±1) 10 |
| El Arrejuntado | `triple_a`/`triple_b` 600 · `signo_triple` 6000 · `arrimao` (4 cifras) 6000 · `pegadito` (5 cifras) 60000 |
| Cazalotón | `tripleta` (3 figuras del sorteo) 200 |
| Loto Chaima | `tripleta` 50 |

**Cómo se usa**:
- **Cambiar un valor** (lo más común): ej. subir el `cruzado` del Chance de 3000 → 3500 ⇒ Bs 10 apostados a Cruzado (ambas puntas) pasan a pagar Bs 35.000.
- **Agregar una clave** solo si el juego realmente la liquida en la venta (el editor te sugiere las válidas: las del plugin + el catálogo oficial; una clave desconocida es rechazada con 422).
- **Quitar una clave** = ese tipo de apuesta deja de tener premio configurado (cae al base si el juego la deriva así; no lo uses para "desactivar" ventas — para eso está `Activo`).

## Comodines — tipos, mecánica y claves reales

El comodín **depende del resultado** (scrapers lo capturan). Regla del motor:

- **`flag` / `letra` / `numero` → REEMPLAZAN** el multiplicador vigente (si hay varios, **gana el mayor**).
- **`palabra` + `acumulativo: true` → SUMA** (+N) al multiplicador vigente.

| Juego | Clave | Tipo | Efecto | Ejemplo real |
|---|---|---|---|---|
| Mega Animal 40 | `mega` | flag (`comodin:true`) | Reemplaza: paga **40×** (base 30) | Bs 10 con MEGA → Bs 400 |
| Selva Plus | `comodin-a` | letra (`comodin:"A"`) | Reemplaza: **160×** | Bs 10 con A → Bs 1.600 |
| Selva Plus | `comodin-b` | letra (`comodin:"B"`) | Reemplaza: **200×** | Bs 10 con B → Bs 2.000 |
| El Guacharito | `guacharito-99` | numero (figura 99) | Reemplaza: **150×** | Bs 10 → Bs 1.500 |
| Guácharo Activo | `guacharo-75` | numero (figura 75) | Reemplaza: **120×** | Bs 10 → Bs 1.200 |
| Monje Millonario | `patronus-75` | numero (figura 75) | Reemplaza: **120×** | Bs 10 a la figura 75 → Bs 1.200 |
| Monje Millonario | `patronus-palabra` | palabra (acumulativo) | SUMA **+20×** al vigente | figura normal: 50+20 = **70×**; figura 75: 120+20 = **140×** |

Campos de cada comodín en el editor: `tipo` (flag | letra | numero | palabra), `premio_multiplo` (entero ≥ 1) y `acumulativo` (solo visible/válido con `palabra`).

**Ejemplo concreto de cambio**: si el cliente decide que MEGA pague **45×**: editar `mega` → `premio_multiplo` 45 → Guardar. Aplica a los sorteos siguientes; lo ya vendido conserva 40.

## Ejemplo completo de `premios` (Monje Millonario)

```json
{
  "base": 50,
  "modalidades": {},
  "comodines": {
    "patronus-75":      { "tipo": "numero",  "premio_multiplo": 120, "nombre": "Patronus" },
    "patronus-palabra": { "tipo": "palabra", "premio_multiplo": 20, "acumulativo": true, "nombre": "PATRONUS" }
  }
}
```

Y uno con modalidades (Triple Chance):

```json
{
  "base": 600,
  "modalidades": {
    "cruzado": 3000, "cruzado_10": 10,
    "triple_a_b": 200000, "solo_a_b": 150,
    "punta": 60, "terminal": 60,
    "signo_triple": 6000, "signo_terminal": 600, "signo_solo": 6
  },
  "comodines": {}
}
```

## Validaciones del editor (qué rechaza y por qué)

- `base`: entero **≥ 1**.
- `modalidades`: clave válida (plugin ∪ catálogo oficial) y valor entero **≥ 1**.
- `comodines`: `tipo` válido, `premio_multiplo` entero **≥ 1**, `acumulativo` solo con `palabra`.
- **la-ricachona** no se puede editar (juego sin fuente oficial → deshabilitado, 422).
- Guardar es **atómico**: enviás el set completo (base + modalidades + comodines) y el backend reemplaza `premios` de una sola vez (no borra otras configuraciones del juego).
- Todo queda **auditado** (before/after + usuario).

## Errores comunes

1. **Confundir reemplazo con suma**: `flag`/`letra`/`numero` *ganan el mayor*; `palabra` *suma al vigente*.
2. **Agregar modalidades "de adorno"** que la venta no liquida → claves inválidas son rechazadas; no insistas con claves inventadas.
3. **Esperar retroactividad**: lo vendido mantiene su premio. Si necesitás corregir algo ya vendido, se resuelve operativamente (no editando `premios`).
4. **Poner `acumulativo` en un tipo que no es palabra** → 422.
5. **Editar `la-ricachona`** → 422 (por diseño).
