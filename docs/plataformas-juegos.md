# Plataformas multi-juego y candidatos de integración

Referencia operativa: qué plataformas alojan varios juegos bajo un mismo backend, cómo detectarlas
al recibir nuevas URLs y qué juegos candidatos quedan pendientes de decisión del cliente.

> Última actualización: 2026-09-12 (integración de La Granjita; La Ricachona en curso).

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

**Detectada con**: La Ricachona versión triples (integración en curso).

**Patrón de datos** (HTML server-rendered por fecha, sin API pública):

```
GET https://laricachona.com/?date=YYYY-MM-DD
```

- Sorteos de **triples**: artículos `tripleResultArticle` con hora `<h1>` y 3 `<p>`; el del medio es el
  número de 3 dígitos del sorteo (los laterales son decorativos `-1`/`+1` del último par). Sin signo.
- Sorteos de **animalitos**: artículos `animalsResultArticle` con hora + imagen del animal.
- Sorteos no ocurridos: `--` / `---` (se saltan).
- Los sorteos de hoy se renderizan sin parámetro; `?date=YYYY-MM-DD` renderiza fechas pasadas.

**Productos del portal**: La Ricachona triples (12 sorteos, 08:05–19:05, cada hora `:05`) ·
La Ricachona animalitos (sección `#animalitos`, cada hora `:10`) — ⏳ candidato.

**Cómo reconocer este patrón**: sitio Laravel clásico con jQuery + datepicker, secciones `#triples`/`#animalitos`,
resultados server-rendered con `?date=`.

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
- [ ] La Ricachona animalitos (laricachona.com)
