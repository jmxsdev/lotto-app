# Motor de premios — arquitectura, gaps y pendientes

Documento de trabajo del **motor de premios** (ciclo futuro). El ciclo de scrapers NO lo toca.
Referencias: `docs/estrategia-scrapers-premios.md` (plan de ciclos y fases) ·
`docs/inconsistencias.md` (registro consolidado) · `docs/comparacion-juegos.md` (hallazgos por juego).

> Creado: 2026-09-14.

## Arquitectura actual (resumen)

- **Plugins** `App\Plugins\Juegos\{Animalitos, Tripletas, Terminales}`: implementan `JuegoInterface`
  (`validarApuesta`, `calcularPremio`, `obtenerReglas`, `obtenerOpciones`, `getValidationRules`,
  `getValidationMessages`). **El multiplicador está hardcodeado en cada plugin**
  (`Animalitos` 30×, `Tripletas` 30×, `Terminales` 20×).
- **`JuegoPluginManager`**: resuelve el plugin por juego (`plugin_juegos`) y envuelve las llamadas
  (`calcularPremio(Juego, apuesta, resultados)`, `validarApuesta`, `getOpciones`…).
- **`calcularPremio($apuesta, $resultados)`**: recibe el JSON `numeros_ganadores` del resultado y
  devuelve `['premio_bs' => …, 'premio_usd' => …]`.
- **Liquidación automática**: `ScrapeResultsJob` → `ApuestaService::verificarGanadores($resultado)` →
  `calcularPremio` → `DetalleApuesta.premio_ganado` + actualización de tickets.
  También llaman a `calcularPremio`: `PagoController` (al pagar) y `TicketController` (al mostrar/crear).
- **`config.premio_multiplo`**: **NO se usa al liquidar** — solo lo consume el export del catálogo
  JSON del front (`JuegoCatalogoService`).

## Gaps anotados (a resolver en el ciclo del motor)

### H13 — Normalización de acentos (CRÍTICO) ⚠️ ANOTADO

`Animalitos::calcularPremio` compara:

```php
strtolower($animalApostado) === strtolower($animalGanador)
```

**sin normalizar acentos**. El feed oficial entrega nombres sin acentos ("Delfin", "Caiman") y
nuestras opciones tienen acentos ("Delfín", "Caimán") → nunca igualan → **el premio sale 0** para
animales acentuados (Delfín, Ciempiés, Alacrán, Águila, Ratón, Caimán, Tucán, Chigüire, …).
**Afecta a toda la familia Lotto Activo + Monje Millonario.**

**Fix propuesto**: normalizar ambos lados en las comparaciones (p. ej. `Str::ascii()` + lowercase, o
`transliterator_transliterate`) en los 3 plugins, y añadir tests con nombres acentuados
(apuesta "Delfín" vs resultado "Delfin" → premio > 0).

### Otros gaps

| Gap | Detalle |
|---|---|
| Comodines | MEGA 40× (mega-animal-40, sin representación en datos) y Selva A/B (160×/200×). Requieren campo en `numeros_ganadores` + multiplicador dinámico desde `config`. |
| Modalidades fuera del modelo | Dupleta (2 animalitos/2 sorteos), El Patronus (figura 75 especial de Monje), Punta / Terminal / Aproximación (Táchira, Trío, Fácil), Par Millonario 200.000×, Terminal+Zodiacal. |
| Premios reales por juego | `premio_multiplo` configurado no liquida; hoy paga el genérico del plugin (30/30/20). Hay juegos que pagan 60×–6.000× (H3). |
| Zoológicos propios | La validación de `Animalitos` usa el canónico 0–36; los juegos con zoo propio (Loto Chaima 57, Selva 101+2, Monje 70, Guacharito, Guácharo, Trío 100 terminal) necesitan validarse contra sus opciones reales. |
| Terminales derivadas | Triple Fácil: el terminal es `n % 100` del triple; decidir si el motor lo deriva y modela apuestas terminal/aproximación (H10). |

## Principios acordados

- Fuente de verdad de reglas/premios: **reglamento oficial** u **operación real (feed)** — nunca la informativa.
- **No se simulan datos**: si una mecánica no es observable en la fuente (p. ej. comodines), no se da soporte hasta tener fuente fiable.
- El ciclo del motor se diseñará con el cliente sobre la base de `docs/inconsistencias.md`.
