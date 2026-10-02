# Pendientes de la Taquilla — Accesibilidad y Teclado

> Índice consolidado de pendientes de ambos fronts: **`docs/dev/pendientes-front.md`** (fuente única). Este documento conserva el detalle del ciclo.

> Documento vivo del ciclo `taquilla-venta-agil` (primer merge a main).
> Recopila los pendientes abiertos de accesibilidad/teclado reportados en el
> test [Win], lo implementado en cada iteración y los deferrals conocidos.
> Se actualiza en cada iteración del ciclo.

## Contexto

El ciclo `taquilla-venta-agil` implementó la taquilla 100% teclado (motor de
teclado, zonas de foco, leyenda, guardas, F-keys) junto con el backend de
apuestas y la plantilla de impresión. Tras la primera ronda de pruebas en
Windows quedaron pendientes de navegación que NO se arreglaron en esa
iteración por instrucción del usuario: se documentan aquí para la próxima
iteración del ciclo.

---

## Pendiente ABIERTO — Navegación de Tripletas

- Estado: **ABIERTO** (no resuelto en esta iteración)
- Reportado por: usuario (test [Win], feedback original del fix de zonas)

### Reproducción (tal como lo reportó el usuario)

1. Abrir la taquilla y seleccionar un juego de tripletas (p. ej. Triple Zulia).
2. El foco queda en la zona de selección de tripletas.
3. Con las flechas del teclado, intentar navegar la selección: elegir el tipo
   de tripleta (Triple A / Triple B / Triple C) y recorrer el flujo de
   selección.
4. La selección NO se navega correctamente con las flechas: el foco no sigue
   el recorrido esperado entre las opciones.

### Notas de investigación (para la próxima iteración)

- Revisar `buildZoneGraph` / `enfocarZona` para la familia `zodiacal`:
  el flujo zona `seleccion` → zona `signo` (triple_c) y la navegación entre
  las opciones de tripletas (tipo Triple A/B/C) con ↑/↓ y ←/→.
- Revisar el estado de foco al cambiar de juego: al seleccionar otro juego de
  tripletas el foco puede quedar en una zona que ya no aplica.
- Verificar `itemsDeZona` y los selectores por zona (`.opcion-item`,
  `.modalidad-btn`, `.signo-btn`) contra el DOM real de las tripletas.

---

## Pendiente ABIERTO — Foco de selección para Números

- Estado: **ABIERTO** (workaround implementado, causa raíz sin resolver)
- Reportado por: usuario (test [Win])

### Reproducción

1. Con el foco en la zona de Número (input), el salto de foco al input no tomó
   efecto en runtime al navegar entre dígitos/zonas.
2. El flujo esperado (dígito → foco al número) no se completaba en pantalla.

### Workaround (histórico win-fixes3; actualizado en atajos-2026-09)

- El viejo **F11 «Números»** (salto directo a `#qt-numero`) fue **reasignado**
  en `atajos-2026-09`: F11 pasó a «Limpiar todo» y el salto a Números volvió
  como **F2 «Números»** (foco directo al input; desde ahí Tab→Monto y
  Enter→Añadir). La causa raíz de este pendiente sigue abierta.
- Se mantiene `Menu.setApplicationMenu(null)` (menú Electron desactivado).

### Notas de investigación (para la próxima iteración)

- Revisar `enfocarZona('numero')` y el `focus()` tras re-render: el re-render
  de la lista de horarios (refresh 15s) u otros re-renders pueden robar o
  invalidar el foco sobre el input.
- Verificar la interacción entre el roving focus (tabindex) y los inputs
  nativos `#qt-numero` / `#qt-monto` (que conservan tabindex nativo).

---

## Implementado en esta iteración (win-fixes3)

- [x] **F11 «Números»** (win-fixes3): salto directo al input de Número desde cualquier
      zona. **Reasignado en atajos-2026-09**: F11 = «Limpiar todo» y el salto
      volvió como F2 «Números» (ver sección de remapeo). — 2026-09-21/26
- [x] **Menú Electron desactivado**: `Menu.setApplicationMenu(null)` en
      `main.cjs` antes de `createWindow()` para que F11 no dispare fullscreen.
      Verificar en Windows que F11 ya no hace fullscreen. — 2026-09-21
- [x] **F2 reset total**: «Limpiar todo» limpia jugada y selección por
      completo (win-fixes2 FIX C). — 2026-09-17
- [x] **Dígitos 1-12 en zodiacal**: los dígitos 1-12 seleccionan el signo por
      posición en juegos zodiacales (win-fixes2 FIX B). — 2026-09-17
- [x] **Sigla del signo en POST**: la combinación envía la SIGLA (value), no
      el label (win-fixes2 FIX E). — 2026-09-17
- [x] **Padding `05`→`005`**: tripletas numéricas normalizadas a 3 cifras
      (win-fixes2 FIX D). — 2026-09-17

---

## Implementado (atajos-2026-09) — remapeo completo

- [x] **Mapa nuevo**: F1 repetir última · F2 Números (foco al input; Tab→Monto,
      Enter→Añadir) · F3 eliminar última · F4 pagar/generar · F5 ventas ·
      F6 resultados · F7 ganadores · F8 cuadre · F9 reimprimir · F10 anular
      ticket · F11 limpiar todo · F12 vuelto · **Alt+H** ayuda · Alt+D dashboard
      · **Backspace** elimina el ítem seleccionado del resumen.
- [x] **F10 «Anular ticket»**: modal que exige teclear el serial del último
      ticket pendiente (anti-tecleo) y llama `DELETE /tickets/{id}` — el backend
      existente valida ventana efectiva por taquilla (default 5 min) y que el
      sorteo no haya pasado (422 con mensaje).
- [x] **`ir-numero` recuperado en F2**: el viejo F11 «Números» vuelve al mapa
      como F2 «Números» (foco directo a `#qt-numero`; desde ahí Tab→Monto y
      Enter→Añadir).
- [x] **Alt+H para la ayuda**: decidido Alt+H (en vez de la combinación
      inicial) para evitar el atajo del SO en Windows, que minimiza la ventana.

---

## Backlog de catálogo — Juegos de terminales

- Estado: **ACLARADO** (no es un bug de la UI)
- Consulta del usuario (test con logos): «en terminales solo tengo un solo juego, ¿debería ser así?»

### Hallazgo (verificado contra el catálogo bundled `taquilla/src/data/juegos.json`)

- El catálogo actual tiene **1 solo juego de tipo `terminales`**: `terminal-activo` (Terminal Activo). Los otros tipos: 11 animalitos, 9 tripletas.
- La UI **no oculta** juegos por falta de logo: el render muestra todos los juegos del tipo activo y usa el nombre como respaldo si no hay imagen (todos tienen logo hoy).
- Otros juegos de terminales vistos en el proveedor agregador **no están integrados** en nuestro catálogo todavía (ver `docs/cliente/comparacion-juegos.md`): `terminal-trio`, `terminal-la-granjita`, `triple-centena-terminal` (pendientes de integración).
- Varios juegos de `tripletas` incluyen **modalidades** de terminal/punta (p. ej. trio-activo `punta`/`terminal`, triple-fácil `terminal`, la-ricachona terminal) pero pertenecen a la pestaña Tripletas según su `tipo`.

### Próxima iteración (si el cliente quiere más terminales)

- Integrar los juegos de terminales faltantes end-to-end: seeder/scraper backend + entrada en `docs/juegos.json` + copia bundled + logo en `public/images/juegos/` + manifest.

---

## Deferrals conocidos

- [ ] **Confirm-and-discard en navegación (F5/F6/F8)**: hoy solo navegan a
      ventas/resultados/cuadre; la semántica de confirmación antes de
      descartar líneas sin generar queda diferida (F10 ya no aplica: es
      anular ticket).
- [ ] **Semántica de premios punta/terminal de trio-activo**: requiere plugin
      backend (fuera de alcance del ciclo; no tocar backend en esta iteración).
- [ ] **Fingerprint localStorage por origen**: el fingerprint del dispositivo
      puede diferir entre dev y empaquetado; evaluar separación por origen.
- [ ] **Mapa de teclas configurable (operador)**: hoy el `KEYMAP` está centralizado en `taquilla/src/utils/keyboard.ts` (tecla → acción/nombre/guarda), la leyenda y la ayuda derivan de él y los handlers despachan por NOMBRE de acción — intercambiar funciones entre dos teclas (p. ej. F1 ↔ F11) es editar el mapa en un solo lugar. Una UI de configuración para que el operador reasigne teclas queda como mejora futura.
- [ ] Cualquier otro deferral registrado en el ciclo `taquilla-venta-agil`
      (ver `docs/dev/pendientes-front.md` y las notas del cambio).