# Plataformas multi-juego y candidatos de integración

Referencia operativa: qué plataformas alojan varios juegos bajo un mismo backend, cómo detectarlas
al recibir nuevas URLs y qué juegos candidatos quedan pendientes de decisión del cliente.

> Última actualización: 2026-09-12 (integración de Selva Plus como SEGUNDO producto
> verificado de lotterly.co — Plataforma 3 —; investigación y documentación de
> resultadosvenezuela.com como Plataforma 4).

## Plataforma 1 — premierpluss (portal: lagranjita.com)

**Detectada con**: La Granjita (juego 15, integrado).

**Patrón de datos** (API JSON, sin auth ni anti-bot, soporta fechas pasadas):

```
GET https://www.lagranjita.com/api/results.json?date=YYYY-MM-DD&productId=N
```

**Productos detectados** (sondeo con `productId` 1–8, fechas 2026-09-11/12):

| productId | Producto | Tipo | Horario | Estado |
|---|---|---|---|---|
| 1 | La Granjita | ANIMALES (zoológico canónico) | 08:00–19:00, cada hora `:00` | ✅ Integrado — juego id 14 |
| 2 | Zoológico Activo | ANIMALES77 | cada hora `:00` | ⏳ Candidato |
| 3 | Ruleta Activa | ANIMALES | cada hora `:00` | ⏳ Candidato |
| 4 | LottoMax | ANIMALES | cada hora `:00` | ⏳ Candidato |
| 5 | Lotto Activo | ANIMALES | cada hora `:00` | ⏳ Candidato — ⚠️ verificar si duplica al `lotto-activo` (lottoactivo.com) ya integrado |
| 6 | Granja Millonaria | ANIMALES | — | ⏳ Candidato (sin resultados en el sondeo) |
| 7 | Jungla Millonaria | ANIMALES | — | ⏳ Candidato (sin resultados en el sondeo) |
| 8 | Lotto Rey | ANIMALES | 08:30–… | ⏳ Candidato (sin resultados en el sondeo) |
| — | Granjita Plus (`/granjitaplus`) | ANIMALES77 | cada hora `:10` | ⏳ Candidato |
| — | Terminal La Granjita (`/terminalgranjita`) | TERMINALES | cada hora `:05` | ⏳ Candidato |

**Cómo reconocer esta plataforma en una URL nueva**:

1. El HTML incrusta los resultados en el atributo `props` de Astro (formato devalue, clave `initialData`).
2. Bundle `/_astro/ResultsBoard.*.js` con `fetch('/api/results.json?date=…&productId=…')`.
3. Imágenes servidas desde `cdns2.premierpluss.com/assets_webpages/…`.
4. Prueba rápida para enumerar productos: `curl "<base>/api/results.json?date=<ayer>&productId=<N>"` variando `N`.

## Plataforma 2 — laricachona.com (portal propio, Laravel)

**Detectada con**: La Ricachona versión triples (juego 16, integrado).

**Patrón de datos** (HTML server-rendered por fecha, sin API pública):

```
GET https://laricachona.com/?date=YYYY-MM-DD
```

- Sorteos de **triples**: artículos `tripleResultArticle` con hora `<h1>` y 3 `<p>`; el del medio es el
  número de 3 dígitos del sorteo (los laterales son decorativos `-1`/`+1` del último par). Sin signo.
- Sorteos de **animalitos**: artículos `animalsResultArticle` con hora + imagen del animal.
- Sorteos no ocurridos: `--` / `---` (se saltan).
- Los sorteos de hoy se renderizan sin parámetro; `?date=YYYY-MM-DD` renderiza fechas pasadas.

**Productos del portal**: La Ricachona triples (12 sorteos, 08:05–19:05, cada hora `:05`) — ✅ integrado
(juego id 15, `LaRicachonaScraper`) · La Ricachona animalitos (sección `#animalitos`, cada hora `:10`) — ⏳ candidato.

**Cómo reconocer este patrón**: sitio Laravel clásico con jQuery + datepicker, secciones `#triples`/`#animalitos`,
resultados server-rendered con `?date=`.

## Plataforma 3 — lotterly.co (API results por `product_slug`)

**Detectada con**: Loto Chaima (juego 17, integrado) y Selva Plus (juego 19, integrado) —
DOS productos verificados del mismo backend.

**Patrón de datos** (API JSON, sin auth ni anti-bot, soporta fechas actuales y pasadas):

```
GET https://api.lotterly.co/v1/results/{product_slug}/?exact_date=YYYY-MM-DD
```

**Contrato** (verificado 2026-09-11/12 con datos distintos en ambos productos):

- Respuesta: array de sorteos, uno por horario del día (12 para Loto Chaima: 08:00–19:00
  cada hora `:00`; **13 para Selva Plus: 08:15–20:15 cada hora `:15`**):
  `[{"date":"2026-09-12","time":"08:15:00","result":"87"}, ...]`.
- `time` en 24h `HH:MM:SS`; `result` es un STRING numérico con padding de 2 dígitos salvo
  el cero (`"0"`, `"04"`, `"87"`).
- El sitio resuelve la figura con el string tal cual (`"0"`→Delfín, `"04"`→Alacrán); los
  scrapers manejan también `"00"` (Ballena) y el fallback de padding (`"8"`→"08"→Ratón).
- El API ya filtra por fecha (`exact_date`): no hay que filtrar el resultado.
- La plataforma es **multi-producto por `product_slug`**: otros slugs devuelven 400
  `"product_slug does not exist"` (verificado). Solo se integran `loto-chaima` y
  `selva-plus` en estos WU.

**Productos del portal**:

- **Loto Chaima** — ✅ integrado (juego id 16, `LotoChaimaScraper`), con **zoológico PROPIO
  de 57 animales (0–55)** distinto al canónico (37→Tortuga, 38→Búfalo, 23→Cebra, 46→Puma...),
  registrado como 57 opciones del juego (`JuegoOpcion`, `value` = slug sin acentos vía
  `Str::slug`).
- **Selva Plus** — ✅ integrado (juego id 18, `SelvaPlusScraper`), con **zoológico PROPIO de
  **101 figuras (0–99, Ballena y Delfín comparten el 0)** + **2 comodines** (Comodín A
  "Leoncito" 160×, Comodín B "Selva Plus" 200×) registrados como `config.comodines` del juego
  y como **103 opciones** (`JuegoOpcion`; los comodines con `numero` null y `value`
  `comodin-a`/`comodin-b`). **Premio base 80×** (tabla oficial: 1→80, 5→400, 10→800,
  50→4.000, 100→8.000). El juego lanzó el **2026-09-07**: fechas anteriores devuelven `[]`.
  La representación de los comodines en `result` NO se ha observado aún → el scraper es
  DEFENSIVO (valor crudo en `numeros_ganadores` + warning, sin mapeos inventados).
  ⚠️ El agregador resultadosvenezuela.com declara datos EQUIVOCADOS para este juego
  (38 animalitos/30×/11 sorteos; la verdad oficial es 101+2 comodines/80×/13 sorteos) —
  ver `docs/comparacion-juegos.md` (hallazgo H8).

**Cómo reconocer este patrón**: API REST con `product_slug` en la ruta, filtro `exact_date`,
resultados en 24h y string con padding; se detecta al recibir URLs de tipo
`https://<plataforma>/<juego>/resultados` cuyo frontend consuma este endpoint.

## Plataforma 4 — resultadosvenezuela.com (agregador HTML)

**Detectada con**: Mega Animal 40 (juego 18, integrado).

**Naturaleza**: AGREGADOR de resultados (no la fuente oficial de ningún juego). El cliente no
encontró página oficial para Mega Animal 40 y eligió este agregador; el sitio publica datos
"directamente desde fuentes oficiales" según su propio texto, pero para el resto de los juegos
conviene preferir las fuentes oficiales cuando existan (ver `docs/comparacion-juegos.md`).

**Patrón de datos** (HTML server-rendered por fecha, SIN API JSON pública — verificados 404:
`/api*`, `*.json`, `lottery_stats.php` es HTML):

```
GET https://resultadosvenezuela.com/lottery/{slug}              → día en curso (parcial)
GET https://resultadosvenezuela.com/lottery/{slug}?date=YYYY-MM-DD → fecha pasada (completo)
```

- Cada resultado es una card `<div class="result-card">` con `card-time` (12h, p. ej. "08:00 PM"),
  `card-number` (número del animal), `card-name` (nombre del animal) y `card-date` (DD/MM/YYYY);
  la imagen lleva `alt="Animalito X número N - <Juego> <fecha>"`.
- El sitio SOLO renderiza horas ya sorteadas (el día en curso es parcial). Una fecha sin sorteos
  ocurridos renderiza la página completa SIN cards (mensaje "No se encontraron sorteos para este
  periodo") — estado válido, no un error.
- Los juegos de TRIPLES del proveedor renderizan el próximo sorteo como card "Pendiente" (🕒, sin
  `card-number`, `opacity: 0.5`) — el parser debe saltar cards sin número.
- Sitemap público en `https://resultadosvenezuela.com/sitemap.xml` (permite enumerar el catálogo).

**Anti-bot / cortesía** (verificado 2026-09-12):

- Sin challenge anti-bot ni CAPTCHA (respuesta HTML normal con User-Agent de navegador).
- `robots.txt`: `User-agent: *` con `Allow: /` y `Disallow: /cache/ /includes/ /logs/ /scraper/
  /admin-add-result.php /result.php` → **NUNCA** tocar esas rutas (en especial `result.php`, que
  sirve los certificados oficiales de los sorteos).
- Cortesía: pocas requests y `sleep ~2s` entre peticiones (así se hizo el relevamiento del catálogo).

**Catálogo del proveedor** (35 páginas `/lottery/*` en el sitemap, NO integrar otras en este WU):
lotto-activo, la-granjita, granja-millonaria, granjazo, guacharo-activo, guacharito-millonario,
chance-animalitos, centena-animalitos, centena-plus, cazaloton, selva-plus, monje-millonario,
mega-animal-40, ruleta-activa, la-ricachona-animalito, lotto-activo-rd, lotto-activo-rdominicana,
loto-chaima, triple-chance, triple-zulia, triple-tachira, triple-caracas, triple-caliente,
triple-zamorano, triple-uneloton, triple-centena, triple-dorado, triple-facil, la-ricachona,
trio-activo, la-ruca, terminal-trio, terminal-la-granjita, triple-centena-terminal, granjita-plus.

**Producto integrado**: Mega Animal 40 — ✅ integrado (juego id 17, `MegaAnimal40Scraper`), con
zoológico CANÓNICO de 38 animalitos (coincide con el plugin Animalitos: el juego no registra
`JuegoOpcion` propias y el catálogo cae al plugin por fallback), 12 sorteos diarios 09:00–20:00
en bloques Mañana (09-11) / Tarde (12-17) / Noche (18-20) según el Reglamento N° DIF-RGTO-033-00,
operado por **Big Data Tecnology, C.A.** bajo la **Lotería de Cojedes** (Fundación de Beneficencia
Pública y Bienestar Social del Estado Cojedes), sistema certificado por **SENCAMER**. Premio:
**30x normal y 40x cuando el sistema sortea el comodín "MEGA"** (automático, sin costo extra).

**Hallazgo — comodín "MEGA"**: el comodín de 40x NO aparece como marcador en las cards
(escaneadas las fechas 11, 10, 9, 8, 7, 6, 5, 4, 3-sep + 12-sep parcial + 13-sep): las
ocurrencias de "MEGA" en el HTML son el nombre del juego y la ruta de las imágenes
(`/assets/images/animales/mega-animal-40/`). El comodín queda documentado como hallazgo de la
comparación (NO implementado: `premio_multiplo` 30 estático en este WU). Si el proveedor llega a
marcarlo, el scraper deberá capturarlo (p. ej. `comodin: true` en `numeros_ganadores`).

**Cómo reconocer este patrón**: HTML server-rendered con cards `.result-card` (`.card-time`,
`.card-number`, `.card-name`, `.card-date`), filtro `?date=YYYY-MM-DD`, imágenes por juego en
`/assets/images/animales/{slug}/`, secciones de información en acordeones (`#game-info`,
"¿Qué es X?", "¿Cómo jugar y ganar?", FAQs, "⏱️ Horarios").

## Candidatos (para consultar al cliente)

- [ ] Zoológico Activo (premierpluss, pid 2)
- [ ] Ruleta Activa (premierpluss, pid 3)
- [ ] LottoMax (premierpluss, pid 4)
- [ ] Lotto Activo de premierpluss (pid 5) — verificar si es duplicado del ya integrado
- [ ] Granja Millonaria (premierpluss, pid 6)
- [ ] Jungla Millonaria (premierpluss, pid 7)
- [ ] Lotto Rey (premierpluss, pid 8)
- [ ] Granjita Plus (premierpluss, `/granjitaplus`)
- [ ] Terminal La Granjita (premierpluss, `/terminalgranjita`)
- [ ] La Ricachona animalitos (laricachona.com) — ya no aplica: verificado como sección
  `animalsResultArticle` (cada hora `:10`); pendiente de decisión del cliente.
