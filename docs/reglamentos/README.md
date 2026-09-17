# docs/reglamentos — Artefactos oficiales descargados (WU f26 — Cacería de reglamentos)

Todos los reglamentos y afiches oficiales encontrados y descargados el 2026-09-14 (WU f26).
Origen y evidencia por juego en `docs/seguimiento-verificacion.md` (sección H) y
`docs/fuentes-oficiales.md`. Los PDF escaneados (sin capa de texto) quedan para que el
cliente/operador extraiga los textos (el agente no hace OCR).

## PDFs de reglamento

| Archivo | Juego(s) | Fuente | Parseable | Estado |
|---|---|---|---|---|
| `reglamento-lotto-activo.pdf` | lotto-activo (1) | admin.lottoactivo.com (licencia oficial vía `/core/process.php`) | ❌ imagen 1 pág | Descargado; 30× confirmado por FAQ + mislink Ruleta Royal |
| `reglamento-lotto-activo-rd.pdf` | lotto-activo-rd (4) | admin.lottoactivo.com — **mislink**: es el reglamento de "RULETA ROYAL" | ✅ 4 págs | Descargado; confirma zoo 0–36 con 23=Cebra y pago 30× (Art. NOVENA) |
| `reglamento-lotto-activo-rep-dom.pdf` | lotto-activo-rep-dom (5) | admin.lottoactivo.com — mismo mislink Ruleta Royal (md5 idéntico al RD) | ✅ 4 págs | Descargado |
| `reglamento-terminal-activo.pdf` | terminal-activo (3) | admin.lottoactivo.com (`Terminal_Trio.pdf`, md5 idéntico a `Trio_Activo.pdf`) | ✅ 1 pág | Descargado; TRIPLE 600× / TERMINAL 60× / PUNTA 60× (ya verificado en f22) |
| `reglamento-triple-zulia.pdf` | triple-zulia (2) | resultadostriplezulia.com `/images/REGLAMENTO TRIPLE ZULIA NOV2025_.pdf` | ✅ 17 págs | **Aplicado (TDD)**: 600×/60×/6.000×/600× → config |
| `reglamento-triple-caliente.pdf` | triple-caliente (8) | triplecaliente.com `/images/Reglamento TRIPLE CALIENTE.pdf` | ✅ 17 págs | **Aplicado (TDD)**: 600×/60×/6.000×/600× → config; 5 sorteos reglamento vs 3 operación (H18) |
| `reglamento-triple-zamorano.pdf` | triple-zamorano (21) | triplezamorano.com `/images/REGLAMENTO TP ZAMORANO NOV2025.pdf` | ✅ 18 págs | **Aplicado (TDD)**: 600×/60×/5×/6.000×/600×/60× → config (H11 resuelto) |
| `reglamento-triple-chance.pdf` | triple-chance (10) | tuchance.com.ve `REGLAMENTO-CHANCE-EN-LINEA-VIGENTE.pdf` | ❌ escaneado 8 págs | Descargado; el cliente extraerá los textos |
| `reglamento-triple-chance-2024.pdf` | triple-chance (10) | tuchance.com.ve `Reglamento.pdf` (2024) | ❌ escaneado 19 págs | Descargado |
| `reglamento-la-granjita.pdf` | la-granjita (14) | cdns2.premierpluss.com (enlazado desde lagranjita.com) | ❌ escaneado 17 págs | Descargado |
| `reglamento-la-ricachona.pdf` | la-ricachona (15) | laricachona.com `assets/files/reglamento_la_ricachona.pdf` | ❌ escaneado 15 págs | Descargado |

## Licencias RUNLOT (autorizaciones, junto a los reglamentos de la misma casa)

| Archivo | Juego | Fuente |
|---|---|---|
| `licencia-triple-caliente-runlot.pdf` | triple-caliente (8) | triplecaliente.com `/licencia/RUNLOT_Triple_Calientes.pdf` |
| `licencia-triple-zamorano-runlot.pdf` | triple-zamorano (21) | triplezamorano.com `/licencia/RUNLOT_TP_ZAMORANO_28.11.2026.pdf` |
| `licencia-triple-zulia-runlot.pdf` | triple-zulia (2) | resultadostriplezulia.com `/licencia/RUNLOT_TP_ZULIA.pdf` |

## Afiches oficiales (familia Lotto Activo — lottoactivo.com/descargas/)

| Archivo | Contenido |
|---|---|
| `afiche-lotto-activo-animalitos.jpg` | Afiche "ANIMALITOS" |
| `afiche-lotto-activo-triple-terminal-v1.jpg` | Afiche "GANA CON EL TRIPLE Y EL TERMINAL" v1 |
| `afiche-lotto-activo-triple-terminal-v2.jpg` | Afiche "GANA CON EL TRIPLE Y EL TERMINAL" v2 |
| `afiche-lotto-activo-ganar-divertido.jpg` | Afiche "GANAR SIEMPRE SERÁ DIVERTIDO" |
| `afiche-lotto-activo-pendon-resultados.jpg` | Pendón de resultados |
| `afiche-lotto-activo-ruleta-animales-grupos.jpg` | Ruleta — animales y grupos (zoo oficial) |
| `afiche-lotto-activo-terminal.jpg` | Terminal de Lotto Activo |

## No publicados (documentado con evidencia)

- **monje-millonario (6)**: `Lotto_Activo_2.pdf` → 404 en admin.lottoactivo.com (+6 variantes
  de nombre probadas). Premio de El Patronus sin fuente (H14).
- **el-arrejuntado (11)**: landing SPA + backend API-only; rutas `/reglamento`, `/docs`,
  `/api/v1/reglamentos/`, `/openapi.json` → 404.
- **el-guacharito (12), guacharo-activo (13), loto-chaima (16), selva-plus (18),
  triple-facil (20)**: los bundles oficiales (lotterly SPA) no contienen reglamento;
  ruta `/reglamento` devuelve el mismo index (catch-all). Todos: "Operado bajo licencia de la
  Lotería de Oriente".
- **mega-animal-40 (17)**: reglamento N° **DIF-RGTO-033-00** (14-nov-2023) solo referenciado
  (RV + sitio oficial megaanimal40.com); el sitio oficial confirma premios **30×/40× comodín
  MEGA**, 12 sorteos 09:00–20:00 y 38 figuras.