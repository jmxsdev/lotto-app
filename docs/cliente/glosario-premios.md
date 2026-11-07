# Glosario de premios y modalidades

Referencia corta para no confundir términos entre los tipos de juego. Fuente de
verdad del dinero: `config.premios` de cada juego (`backend/app/Support/PremiosOficiales.php`),
resuelto por `PremiosEngine`.

## 1. Aviso de nombres: `tripletas` ≠ `tripleta`

Son dos cosas distintas que se llaman parecido:

| Término | Dónde vive | Qué es |
|---|---|---|
| **`tripletas`** | Tipo de juego (`juegos.type`) | Familia de juegos de **números de 3 cifras** (000–999): Triple Zulia, Trío Activo, Triple Caliente, Triple Chance, Triple Táchira, Triple Zamorano, Triple Fácil, El Arrejuntado, La Ricachona. Sus modalidades son punta/terminal/uña/signo/etc. |
| **`tripleta`** | Modalidad de **animalitos** (`premios.modalidades.tripleta`) | Solo en **Cazalotón** y **Loto Chaima**. Es una jugada de **3 animales** (no de 3 cifras). Clave canónica `tripleta`. |
| **`terminales`** | ⚠️ **No es un tipo de juego** | El sistema **no soporta** un tipo de juego de terminales: la jugada de terminal es una **modalidad** de los juegos de `tripletas`. El nombre comercial *Terminal Activo / Terminal Trío* identifica la modalidad de terminal del Trío Activo. |
| **`terminal`** | Modalidad (canónica) | Las **2 últimas cifras** de un triple, **dentro de un juego de `tripletas`** (Trío Activo, Zulia, Caliente, Chance, Táchira, Zamorano, Fácil). Es una **modalidad**, no un tipo de juego. |

> Regla: si el juego es `type: animalitos` y la clave es `tripleta`, se habla de
> 3 figuras de animalitos. Si el juego es `type: tripletas`, se habla de números
> de 3 cifras y sus modalidades. **Nunca** mezclar ambos vocabularios.
>
> Y ojo: **`terminal` es una modalidad** de los juegos de `tripletas`; **no** existe un
> tipo de juego `terminales` (mismo patrón de choque que `tripletas` (tipo) vs `tripleta`
> (modalidad de animalitos)).

## 1.1 ¿Tipo de juego o modalidad?

- **Tipo de juego** (`juegos.type`): `animalitos` | `tripletas`. Controla la
  agrupación en la taquilla, el **tope de comisión por tipo**
  (`ComisionService::topePorTipo`: animalitos 16% / tripletas 25% / resto 100%), y algunas
  ramas de scraper. No es dinero ni sorteo: es clasificación.
- **Modalidad** (`premios.modalidades`): la forma de la jugada dentro de un tipo (punta,
  terminal, uña, signo_triple, tripleta, …). Su multiplicador vive en `config.premios`.

> **Decisión (2026-10-16)**: `terminal` es una **modalidad** de los juegos de `tripletas`; **no
> existe un tipo de juego de terminales**. El catálogo queda en **20 juegos**: la jugada de
> terminal del Trío Activo se comercializa como *Terminal Activo / Terminal Trío* (60×, las
> 2 últimas cifras de su triple). Al integrar terminales de otros operadores, se modelan como
> modalidad de un juego de `tripletas`, nunca como juego o tipo propio.

## 2. Modalidades de los juegos de `tripletas`

Cada sorteo canta uno o varios **triples** (números de 3 cifras), normalmente
A / B / C. Ejemplo con el triple **310**:

| Modalidad (clave canónica) | Definición | Sobre 310 |
|---|---|---|
| Triple (A/B/C) | Número exacto de 3 cifras | `310` |
| **Punta** | Las **2 primeras** cifras del triple | `31` |
| **Terminal** = **Cola** | Las **2 últimas** cifras del triple | `10` |
| **Uña** | La **última** cifra (1 dígito) | `0` |
| **Signo** | Uno de los 12 signos zodiacales | Escorpio |
| **Zodiacal** = **Astro** = **Signo Caliente** = **C + Signo** | Triple exacto **+** signo (hay que acertar **ambos**) | `310` + Escorpio |
| **Terminal + Signo** (Cola+Signo) | Terminal **+** signo | `10` + Escorpio |
| **Uña + Signo** | Uña **+** signo | `0` + Escorpio |
| **Signo solo** | Únicamente el signo (solo Triple Chance, 6×) | Escorpio |
| **Aproximación** | Terminal ±1 (solo Triple Fácil, 10×) | `09` o `11` |
| **Arrimao** (4 cifras) | Número exacto de 4 cifras — El Arrejuntado | `1825` |
| **Pegadito** (5 cifras) | Número exacto de 5 cifras — El Arrejuntado | `10503` |

### Nombres por casa (sinónimos)

- **Cola** (Zulia, Táchira, Zamorano) = **Terminal** (motor). Es la misma jugada: 2 últimas cifras.
- **Zodiacal** (Zulia, Táchira) / **Astro** (Zamorano) / **Signo Caliente** (Caliente) / **C + Signo** (Chance) = **triple exacto + signo**. No es "solo el signo".

### Multi-selección del mismo sorteo (Triple Chance)

- **Triple Fijo (A o B)**: apuestas a **un** triple exacto → 600× (base).
- **Par Millonario (A + B)**: apuestas a **dos** triples a la vez. Acertar **ambos** → 200.000× (`triple_a_b`); acertar **solo uno** → 150× (`solo_a_b`). "Solo A o B" **no** es un triple fijo: es la porción del par.
- **Cruzado**: dos **puntas** (contra la punta de A y la de B). Ambas → 3.000× (`cruzado`); una → 10× (`cruzado_10`).

## 3. Contrato de datos (scraper → motor)

Para que el motor liquide, el scraper debe persistir en `numeros_ganadores`:

- `triple_a`, `triple_b`, `triple_c`: los triples del sorteo (string/serializable, el motor hace padding a 3).
- `signo`: **sigla** de 3 letras (`ESC`) o **label** (`Escorpio`); el motor normaliza ambos.
- **Invariante clave**: el signo viaja **junto al `triple_c`** (es el slot de referencia de `signo_triple`, `signo_terminal` y `signo_uña`). Todos los scrapers de tripletas lo cumplen.

## 4. Comodines de `animalitos` (recordatorio)

- **Boost on match** (`tipo: flag|numero|palabra`): el comodín llega con la figura dibujada y **sube** el multiplicador (Mega 40×, Guacharito 99→150×, Guácharo 75→120×, Patronus 75→120× / +20×).
- **Figura apostable independiente** (`tipo: letra`): Selva Plus A/B — se apuesta **al comodín**; el comodín **es** el resultado.
