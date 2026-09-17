# Premiación de los juegos — guía didáctica (21 juegos)

> Documento para el cliente. Explica, con ejemplos concretos, **cómo se juega, qué se apuesta,
> cuándo se gana y cuánto se paga** en cada uno de los 21 juegos del sistema.
> Los valores de pago (multiplicadores) provienen de `docs/multiplicadores-juegos.md`, que consolida
> los **reglamentos oficiales** y la operación real de cada juego. No se inventa ningún valor: si un
> juego no tiene fuente oficial con valores, queda **deshabilitado** (ver `la-ricachona`).

## Cómo leer esta guía

- El pago se expresa en **multiplicador** (×): el número por el que se multiplica lo apostado.
  **Ejemplo:** si un premio es "30×" y apuestas **Bs. 10**, ganas `10 × 30 = Bs. 300`.
- **Regla de redondeo**: todo monto de premio se calcula con **máximo 2 decimales**
  (`premio = monto × multiplicador`, redondeado a 2 decimales). La moneda del premio es la misma de la
  apuesta: **Bs. o USD** según la tasa aplicada a la venta.
- **Regla de soporte**: **sin fuente oficial con valores → juego deshabilitado** (`active=false`).
  Hoy solo aplica a `la-ricachona` (marcada como deshabilitada/pendiente al final del documento).
- Algunos juegos tienen **comodines** (figuras o condiciones especiales que pagan más) y **modalidades**
  (formas de acertar con premio distinto). Se explican juego por juego.

## Resumen de multiplicadores oficiales

| # | Juego | Tipo | Premio base | Comodín / modalidad destacada |
|---|-------|------|-------------|-------------------------------|
| 1 | Lotto Activo | Animalitos | 30× | Dupleta 1.000× |
| 2 | Triple Zulia | Triples | 600× | Zodiacal 6.000× |
| 3 | Terminal Trío | Terminales | 60× | — |
| 4 | Lotto Activo RD | Animalitos | 30× | — |
| 5 | Lotto Activo Rep. Dom. | Animalitos | 30× | — |
| 6 | Monje Millonario | Animalitos | 50× | Patronus (75) 120× |
| 7 | Trío Activo | Triples | 600× | Terminal/Punta 60× |
| 8 | Triple Caliente | Triples | 600× | Signo 6.000× |
| 9 | Cazalotón | Animalitos | 30× | Dupleta 800× / Tripleta 200× |
| 10 | Triple Chance | Triples | 600× | A+B 200.000× / C+Signo 6.000× |
| 11 | El Arrejuntado | Triples | 40× | Pegadito 60.000× |
| 12 | El Guacharito | Animalitos | 70× | Guacharito (99) 150× |
| 13 | Guácharo Activo | Animalitos | 60× | Guácharo (75) 120× |
| 14 | La Granjita | Animalitos | 30× | — |
| 15 | La Ricachona | Triples | — | **Deshabilitado** (sin fuente) |
| 16 | Loto Chaima | Animalitos | 40× | Tripleta 50× |
| 17 | Mega Animal 40 | Animalitos | 30× | Comodín MEGA 40× |
| 18 | Selva Plus | Animalitos | 80× | Comodín A 160× / B 200× |
| 19 | Triple Táchira | Triples | 500× | Zodiacal 5.000× |
| 20 | Triple Fácil | Triples | 700× | Terminal 60× / Aprox 10× |
| 21 | Triple Zamorano | Triples | 600× | Astro 6.000× |

---

## Juegos de Animalitos

En los juegos de **animalitos** eliges una figura (animal) de un zoológico de figuras numeradas. Si
tu animal sale en el sorteo, ganas el premio base. Algunos juegos tienen un zoológico propio más
grande (Monje 77, Guácharo 77, Guacharito 101, Selva 103, Chaima 57) y comodines.

### 1. Lotto Activo — 30×

- **Cómo se juega**: eliges 1 animalito de las 38 figuras (Ballena/Delfín = 0 … Culebra = 36).
  12 sorteos diarios (08:00–19:00).
- **Qué se apuesta**: un animalito y el monto.
- **Cuándo ganas**: si tu animalito sale en el sorteo al que apostaste.
- **Cuánto pagas**: **30×** el monto apostado.
- **Ejemplo**: apuestas **Bs. 10** al Zorro → sale Zorro → ganas `10 × 30 = Bs. 300`.
- **Dupleta** (modalidad pendiente de soporte): eliges 2 animalitos en 2 sorteos consecutivos en
  orden exacto → paga **1.000×**. **Ejemplo**: Bs. 5 a Dupleta Zorro→Tigre → `5 × 1.000 = Bs. 5.000`.

### 2. Lotto Activo RD — 30×

Igual que Lotto Activo (38 figuras, 30×). **Ejemplo**: Bs. 10 al Caballo → `10 × 30 = Bs. 300`.

### 3. Lotto Activo Rep. Dom. — 30×

Igual que Lotto Activo (38 figuras, 30×). **Ejemplo**: Bs. 10 al Tigre → `10 × 30 = Bs. 300`.

### 4. Monje Millonario — 50× (Patronus 120×)

- **Cómo se juega**: eliges 1 de las **77 figuras** (números 0–75, zoológico propio; el 0 está
  duplicado como Delfín/Ballena). Sorteos del feed oficial Lotto Activo 2.
- **Qué se apuesta**: una figura y el monto.
- **Cuándo ganas**: si tu figura sale en el sorteo.
- **Cuánto pagas** (reglamento oficial "Lotto Activo 2"):
  - **Figura normal**: **50×**.
  - **Palabra PATRONUS**: cuando en el sorteo sale la palabra "PATRONUS" sobre una figura normal, el
    acumulado es **50× + 20× = 70×**.
  - **El Patronus (figura 75)**: **120×**.
- **Ejemplos**:
  - Bs. 10 a la figura 42 (Tucán) → `10 × 50 = Bs. 500`.
  - Bs. 10 a la figura 42 y sale la palabra PATRONUS → `10 × 70 = Bs. 700`.
  - Bs. 10 al Patronus (75) → `10 × 120 = Bs. 1.200`.

### 5. Cazalotón — 30× (Dupleta 800× / Tripleta 200×)

- **Cómo se juega**: eliges 1 animalito (38 figuras). 11 sorteos (09:00–19:00).
- **Cuánto pagas**: **30×** simple; **Dupleta** (2 animalitos) **800×**; **Tripleta** (3 animalitos)
  **200×**.
- **Ejemplo**: Bs. 10 al Perico → `10 × 30 = Bs. 300`.

### 6. El Guacharito — 70× (Guacharito 150×)

- **Cómo se juega**: eliges 1 de las **101 figuras** (00 Ballena, 0 Delfín, 01–99 Guacharito).
- **Cuánto pagas**: **70×** base; la figura **99 (Guacharito)** paga **150×**.
- **Ejemplo**: Bs. 10 a la figura 99 → sale 99 → `10 × 150 = Bs. 1.500` (comodín).

### 7. Guácharo Activo — 60× (Guácharo 120×)

- **Cómo se juega**: eliges 1 de las **77 figuras** (00 Ballena, 0 Delfín, 01–75 Guácharo).
- **Cuánto pagas**: **60×** base; la figura **75 (Guácharo)** paga **120×**.
- **Ejemplo**: Bs. 10 a la figura 75 → sale 75 → `10 × 120 = Bs. 1.200` (comodín).

### 8. La Granjita — 30×

- **Cómo se juega**: eliges 1 animalito (38 figuras).
- **Cuánto pagas**: **30×**.
- **Ejemplo**: Bs. 10 a la Vaca → `10 × 30 = Bs. 300`.

### 9. Loto Chaima — 40× (Tripleta 50×)

- **Cómo se juega**: eliges 1 de las **57 figuras** (0–55).
- **Cuánto pagas**: **40×** base; **Tripleta Loto Chaima** **50×**.
- **Ejemplo**: Bs. 10 a la figura 20 → `10 × 40 = Bs. 400`.

### 10. Mega Animal 40 — 30× (Comodín MEGA 40×)

- **Cómo se juega**: eliges 1 animalito (38 figuras). 12 sorteos (09:00–20:00). El nombre viene del
  comodín MEGA: en algunos sorteos sale el **MEGA**, que sube el premio **sin costo** para el jugador.
- **Cuánto pagas**: **30×** normal; si el sorteo trae **MEGA**, **40×**.
- **Ejemplo**: Bs. 10 al Zorro en un sorteo con MEGA → `10 × 40 = Bs. 400` (en vez de 300).

### 11. Selva Plus — 80× (Comodín A 160× / B 200×)

- **Cómo se juega**: eliges 1 de las **103 figuras** (101 figuras 0–99 + 2 comodines). 13 sorteos
  (08:15–20:15).
- **Cuánto pagas**: **80×** base; **Comodín A "Leoncito" 160×**; **Comodín B "Selva Plus" 200×**.
- **Ejemplo**: Bs. 10 y sale el Comodín B → `10 × 200 = Bs. 2.000`.

---

## Juegos de Triples

En los **triples** eliges un número de 3 cifras (000–999). Según la modalidad, puedes acertar el
triple exacto, las 2 últimas cifras (terminal/cola), las 2 primeras (punta), o combinarlo con un
**signo zodiacal** para multiplicar el premio. Cada juego tiene su propio esquema de modalidades.

### 12. Triple Zulia — 600×

- **Cómo se juega**: eliges un triple (A/B/C, 000–999) y opcionalmente un signo del Zodiaco del Zulia
  (12 signos).
- **Cuánto pagas**:
  - **Triple** (A, B o C): **600×**.
  - **Cola** (2 últimas cifras): **60×**.
  - **Zodiacal** (triple + signo): **6.000×**.
  - **Terminal + Zodiacal** (cola + signo): **600×**.
- **Ejemplos**:
  - Bs. 10 al triple 452 → sale 452 → `10 × 600 = Bs. 6.000`.
  - Bs. 10 a 452 + signo Virgo → sale 452-VIR → `10 × 6.000 = Bs. 60.000`.

### 13. Trío Activo — 600×

- **Cómo se juega**: eliges un triple de 3 cifras (000–999). 12 sorteos (08:00–19:00). Sin zodiaco.
- **Cuánto pagas**: **Triple 600×** · **Terminal** (2 últimas cifras) **60×** · **Punta** (2 primeras)
  **60×**.
- **Ejemplo**: Bs. 10 al triple 847 → sale 847 → `10 × 600 = Bs. 6.000`.

### 14. Triple Caliente — 600×

- **Cómo se juega**: eliges un triple (A/B/C) y opcionalmente un signo (12 signos). 3 sorteos en la
  operación real (13:00/16:30/19:10).
- **Cuánto pagas**: **Triple 600×** · **Terminal 60×** · **Signo Caliente 6.000×** ·
  **Terminal + Signo 600×**.
- **Ejemplo**: Bs. 10 al triple 310 → sale 310 → `10 × 600 = Bs. 6.000`.

### 15. Triple Chance — 600× (A+B 200.000× / C+Signo 6.000×)

- **Cómo se juega**: secciones A y B (Millonario) + C con signo zodiacal. 11 sorteos (09:00–19:00).
- **Cuánto pagas** (reglamento oficial):
  - **Triple Fijo** (A o B): **600×**.
  - **A + B** (ambos triples): **200.000×**.
  - **Solo A o B**: **150×** (reglamento).
  - **Punta 60×** · **Terminal 60×** · **Cruzado 3.000×/10×**.
  - **C + Signo**: **6.000×** (reglamento).
  - **Terminal + Signo 600×** · **Signo 6×**.
- **Ejemplo**: Bs. 10 al Triple A y B → salen ambos → `10 × 200.000 = Bs. 2.000.000`.

### 16. El Arrejuntado — 40× base (6 modalidades)

- **Cómo se juega**: en **cada sorteo** se cantan, a la vez, **6 resultados** sobre los que puedes
  apostar por separado:
  1. **Animalito** — una figura (como los juegos de animalitos).
  2. **Triple A** — un número de 3 cifras (000–999).
  3. **Triple B** — otro número de 3 cifras (000–999).
  4. **Triple + Signo** — uno de los triples combinado con su signo zodiacal.
  5. **El Arrimao** — un número de **4 cifras** (ej. `2091`).
  6. **El Pegadito** — un número de **5 cifras** (ej. `01963`).
- **Qué se apuesta**: eliges una modalidad y el número/figura exacto de esa modalidad. Ganar el
  Pegadito o el Arrimao es **acertar el número exacto** de 5 o 4 cifras (no es un comodín: es una
  modalidad propia del sorteo).
- **Cuánto pagas** (reglamento oficial + web oficial para el Pegadito):
  - **Animalito**: **40×** (base).
  - **Triple A** y **Triple B**: **600×**.
  - **Triple + Signo**: **6.000×**.
  - **El Arrimao** (4 cifras exactas): **6.000×**.
  - **El Pegadito** (5 cifras exactas): **60.000×**.
- **Ejemplos**:
  - Bs. 10 al Animalito → sale tu animalito → `10 × 40 = Bs. 400`.
  - Bs. 10 al Triple A 452 → sale 452 → `10 × 600 = Bs. 6.000`.
  - Bs. 10 al Triple + Signo → sale tu triple con su signo → `10 × 6.000 = Bs. 60.000`.
  - Bs. 10 al Arrimao 2091 → sale `2091` → `10 × 6.000 = Bs. 60.000`.
  - Bs. 10 al Pegadito 01963 → sale `01963` → `10 × 60.000 = Bs. 600.000`.

### 17. Triple Táchira — 500×

- **Cómo se juega**: eliges un triple (A/B) y opcionalmente un signo (12 signos). 3 sorteos
  (13:15/16:45/22:10).
- **Cuánto pagas**: **Triple 500×** · **Terminal/Cola 50×** · **Zodiacal 5.000×**.
- **Ejemplo**: Bs. 10 al triple 203 → sale 203 → `10 × 500 = Bs. 5.000`.

### 18. Triple Fácil — 700× (Terminal 60× / Aproximación 10×)

- **Cómo se juega**: eliges un triple de 3 cifras (000–999, entrada libre). 12 sorteos (08:00–19:00).
  El "terminal" es **derivado**: los 2 últimos dígitos del triple (`n % 100`), y la aproximación es
  el terminal ±1.
- **Cuánto pagas** (reglamento oficial): **Triple 700×** · **Terminal 60×** · **Aproximación 10×**.
- **Ejemplos**:
  - Bs. 10 al triple 346 → sale 346 → `10 × 700 = Bs. 7.000`.
  - Bs. 10 al terminal 46 (2 últimas cifras del triple) → sale el triple 346 → `10 × 60 = Bs. 600`.
  - Bs. 10 a la aproximación 45 (terminal ±1) → sale el triple 346 → `10 × 10 = Bs. 100`.

### 19. Triple Zamorano — 600×

- **Cómo se juega**: eliges un triple (A/C) y opcionalmente un signo (12 signos). 5 sorteos
  (10:00–19:00; domingos solo 19:00).
- **Cuánto pagas**: **Triple 600×** · **Cola 60×** · **Uña 5×** · **Astro (triple+signo) 6.000×** ·
  **Cola+Signo 600×** · **Uña+Signo 60×**.
- **Ejemplo**: Bs. 10 al triple 105 → sale 105 → `10 × 600 = Bs. 6.000`.

### 20. La Ricachona — DESHABILITADO / PENDIENTE

- **Estado**: **deshabilitado** (`active=false`) y **pendiente de fuente**. El reglamento (2022) no
  trae valores de premio y no se localizó una fuente oficial con multiplicadores. Por la regla de
  soporte del sistema, **no se brinda soporte** hasta tener la fuente oficial con valores.

---

## Juegos de Terminales

### 21. Terminal Trío (Terminal Activo) — 60×

- **Cómo se juega**: eliges un terminal de 2 cifras (00–99).
- **Qué se apuesta**: el número de 2 cifras y el monto.
- **Cuándo ganas**: si el terminal sale en el sorteo.
- **Cuánto pagas**: **60×** (reglamento oficial — H12 resuelto: se usa 60×).
- **Ejemplo**: Bs. 10 al terminal 37 → sale 37 → `10 × 60 = Bs. 600`.

---

## Notas finales

- **Todos los multiplicadores de esta guía son definitivos** y provienen de la fuente indicada en cada
  juego: reglamento oficial (Chance, Terminal Trío, Monje, Triple Fácil, Cazalotón, Guácharo, Chaima,
  Granjita, Táchira, Zamorano, Zulia, Caliente, Trío Activo) o **web/sitio oficial** (El Arrejuntado
  incluye el Pegadito 60.000×, Selva Plus, Mega Animal 40, Guacharito).
- Los **comodines** (MEGA 40×, Selva A/B 160×/200×, Guacharito 99→150×, Guácharo 75→120×, Patronus
  75→120×, palabra PATRONUS +20×) **ya se capturan en los datos** y se liquidan en el ciclo del motor
  de premios.
- El **Pegadito y el Arrimao** del Arrejuntado son **modalidades del sorteo** (acertar el número exacto
  de 5 y 4 cifras), no comodines.
- **Regla de soporte**: sin fuente oficial con valores → juego deshabilitado. `la-ricachona` queda
  deshabilitada (`active=false`) y pendiente de fuente.
- **Regla de redondeo**: todo premio se expresa con **máximo 2 decimales** (`premio = monto ×
  multiplicador`, redondeado a 2 decimales) en la moneda apostada (Bs. o USD según la tasa).
