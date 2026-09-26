# Pendientes de la Taquilla — Accesibilidad y Teclado

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

### Workaround implementado (win-fixes3)

- **F11** salta directo al input de Números (`#qt-numero`) y enfoca la zona
  `numero`. Desde ahí el ruteo existente ya opera: Tab → Monto, Enter → Añadir.
- Se desactivó el menú nativo de Electron (`Menu.setApplicationMenu(null)`) para
  que F11 no dispare el fullscreen del navegador/Electron.

### Notas de investigación (para la próxima iteración)

- Revisar `enfocarZona('numero')` y el `focus()` tras re-render: el re-render
  de la lista de horarios (refresh 15s) u otros re-renders pueden robar o
  invalidar el foco sobre el input.
- Verificar la interacción entre el roving focus (tabindex) y los inputs
  nativos `#qt-numero` / `#qt-monto` (que conservan tabindex nativo).

---

## Implementado en esta iteración (win-fixes3)

- [x] **F11 «Números»**: salto directo al input de Número desde cualquier
      zona (foco `#qt-numero` + zona `numero`; Tab→Monto y Enter→Añadir ya
      operan con el ruteo existente). — 2026-09-21
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

## Backlog de catálogo — Juegos de terminales

- Estado: **ACLARADO** (no es un bug de la UI)
- Consulta del usuario (test con logos): «en terminales solo tengo un solo juego, ¿debería ser así?»

### Hallazgo (verificado contra el catálogo bundled `taquilla/src/data/juegos.json`)

- El catálogo actual tiene **1 solo juego de tipo `terminales`**: `terminal-activo` (Terminal Activo). Los otros tipos: 11 animalitos, 9 tripletas.
- La UI **no oculta** juegos por falta de logo: el render muestra todos los juegos del tipo activo y usa el nombre como respaldo si no hay imagen (todos tienen logo hoy).
- Otros juegos de terminales vistos en el proveedor agregador **no están integrados** en nuestro catálogo todavía (ver `docs/comparacion-juegos.md`): `terminal-trio`, `terminal-la-granjita`, `triple-centena-terminal` (pendientes de integración).
- Varios juegos de `tripletas` incluyen **modalidades** de terminal/punta (p. ej. trio-activo `punta`/`terminal`, triple-fácil `terminal`, la-ricachona terminal) pero pertenecen a la pestaña Tripletas según su `tipo`.

### Próxima iteración (si el cliente quiere más terminales)

- Integrar los juegos de terminales faltantes end-to-end: seeder/scraper backend + entrada en `docs/juegos.json` + copia bundled + logo en `public/images/juegos/` + manifest.

---

## Deferrals conocidos

- [ ] **F7/F8/F10 confirm-and-discard**: hoy solo navegan a
      ventas/cuadre/resultados; la semántica de confirmación antes de
      descartar líneas sin generar queda diferida.
- [ ] **Semántica de premios punta/terminal de trio-activo**: requiere plugin
      backend (fuera de alcance del ciclo; no tocar backend en esta iteración).
- [ ] **Fingerprint localStorage por origen**: el fingerprint del dispositivo
      puede diferir entre dev y empaquetado; evaluar separación por origen.
- [ ] Cualquier otro deferral registrado en el ciclo `taquilla-venta-agil`
      (ver `docs/PENDIENTE.md` y las notas del cambio).