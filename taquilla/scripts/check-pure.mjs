#!/usr/bin/env node
/**
 * Harness [Lin] del módulo puro catalogo.ts — PR1 (REQ-CL-01..03, A5).
 * Crece en PR2 (grafos de zonas), PR3b (calcularVuelto), atajos-2026-09
 * (re-mapeo del KEYMAP + helpers de la anulación F10) y S1 front-1-0-0
 * (pagos.ts: payload egreso, moneda derivada, esPagableTicket, premio).
 *
 * Ejecutar desde la raíz del repo:
 *   node taquilla/scripts/check-pure.mjs
 *
 * Requiere Node 24+ (type-stripping nativo para importar .ts).
 */
import { existsSync, readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import {
  cargarCatalogo,
  normalizarLabel,
  saltarPorLetra,
  buscarPorDigito,
  crearBuscadorDigitos,
  signoPorPosicion,
  normalizarNumeroTriple,
  siglaDeSigno,
  labelDeSigno,
  premios,
  modalidadesDe,
  normalizarPremios,
} from '../src/utils/catalogo.ts';
import { buildZoneGraph, routeKey, KEYMAP, esFKey, zonaHorizontal, zonaPendienteSeleccion } from '../src/utils/keyboard.ts';
import {
  destinoNav,
  NAV_GLOBAL,
  teclasLegend,
  ATAJOS_EXTRA,
  normalizarSerial,
  serialCoincide,
  ultimoTicketPendiente,
} from '../src/utils/keyboard.ts';
import {
  alternarHorario,
  marcarTodosVisibles,
  expandirLineas,
  ahoraHHMM,
  horarioExpirado,
  filtrarHorariosFuturos,
  seleccionadosExpirados,
  agruparPorGroupId,
  indiceSeleccionTrasEliminar,
} from '../src/utils/horarios.ts';
import { calcularVuelto } from '../src/utils/vuelto.ts';
import { indiceDestinoFila, indiceDestinoColumna } from '../src/utils/gridNav.ts';
import { formatearJugada, nombresJuegos, labelModalidad } from '../src/utils/ticket.ts';
import {
  monedaDeApuesta,
  esPagableApuesta,
  payloadPago,
  esPagableTicket,
  acumularPremio,
} from '../src/utils/pagos.ts';
import { estadoTicket } from '../src/utils/estados.ts';
import { buildReciboLines, estadoRecibo } from '../src/utils/recibos.ts';
import { badgesResultado } from '../src/utils/resultados.ts';
import { vistaUpdate } from '../src/utils/autoUpdate.ts';
import {
  TIPOS_PAGO,
  monedaDelTicket,
  opcionesPago,
  metodoValido,
  labelTipoPago,
  hayMezclaDeMonedas,
} from '../src/utils/tipoPagoVenta.ts';
import {
  modalidadesDisponibles,
  validarDigitos,
  construirCombinacion,
  construirCombinacionMulti,
  construirTripleta,
  alternarSeleccionAnimal,
  DEFS,
} from '../src/utils/modalidades.ts';

const AQUI = dirname(fileURLToPath(import.meta.url));
const rutaJson = join(AQUI, '..', 'src', 'data', 'juegos.json');
const catalogo = cargarCatalogo(JSON.parse(readFileSync(rutaJson, 'utf8')));

let checks = 0;
let fallos = 0;

function ok(condicion, mensaje) {
  checks += 1;
  if (condicion) {
    console.log(`  ✓ ${mensaje}`);
  } else {
    fallos += 1;
    console.error(`  ✗ ${mensaje}`);
  }
}

console.log('== REQ-CL-01: 21 juegos bundled ==');
ok(catalogo.version === 1, `version === 1 (${catalogo.version})`);
ok(catalogo.juegos.length === 21, `21 juegos (${catalogo.juegos.length})`);

console.log('\n== REQ-CL-02: familias de opciones (11/9/1 por tipo + split por juego) ==');
const porTipo = {};
for (const j of catalogo.juegos) porTipo[j.tipo] = (porTipo[j.tipo] ?? 0) + 1;
ok(porTipo.animalitos === 11, `tipo animalitos: 11 (${porTipo.animalitos})`);
ok(porTipo.tripletas === 9, `tipo tripletas: 9 (${porTipo.tripletas})`);
ok(porTipo.terminales === 1, `tipo terminales: 1 (${porTipo.terminales})`);

const porFamilia = {};
for (const j of catalogo.juegos) porFamilia[j.familia] = (porFamilia[j.familia] ?? 0) + 1;
ok(porFamilia.animalitos === 11, `familia animalitos: 11 (${porFamilia.animalitos})`);
ok(porFamilia.zodiacal === 7, `familia zodiacal: 7 (${porFamilia.zodiacal})`);
ok(porFamilia.numerica === 2, `familia numérica: 2 (${porFamilia.numerica})`);
ok(porFamilia.terminal === 1, `familia terminal: 1 (${porFamilia.terminal})`);

const familiaEsperada = {
  'lotto-activo': 'animalitos',
  'triple-zulia': 'zodiacal',
  'terminal-activo': 'terminal',
  'lotto-activo-rd': 'animalitos',
  'lotto-activo-rep-dom': 'animalitos',
  'monje-millonario': 'animalitos',
  'trio-activo': 'numerica',
  'triple-caliente': 'zodiacal',
  'cazaloton': 'animalitos',
  'triple-chance': 'zodiacal',
  'el-arrejuntado': 'zodiacal',
  'el-guacharito': 'animalitos',
  'guacharo-activo': 'animalitos',
  'la-granjita': 'animalitos',
  'la-ricachona': 'zodiacal',
  'loto-chaima': 'animalitos',
  'mega-animal-40': 'animalitos',
  'selva-plus': 'animalitos',
  'triple-tachira': 'zodiacal',
  'triple-facil': 'numerica',
  'triple-zamorano': 'zodiacal',
};
for (const [slug, familia] of Object.entries(familiaEsperada)) {
  const juego = catalogo.porSlug.get(slug);
  ok(juego && juego.familia === familia, `familia ${slug}: ${familia}`);
}
ok(catalogo.porSlug.get('triple-zulia').opciones.length === 12, 'triple-zulia: 12 signos (zodiacal)');
ok(catalogo.porSlug.get('trio-activo').opciones.length === 100, 'trio-activo: 100 opciones (numérica)');
ok(catalogo.porSlug.get('terminal-activo').opciones.length === 100, 'terminal-activo: 100 opciones (terminal)');

console.log('\n== Índices por id/slug (A5) ==');
ok(catalogo.porId.size === 21, `índice porId: 21 (${catalogo.porId.size})`);
ok(catalogo.porSlug.size === 21, `índice porSlug: 21 (${catalogo.porSlug.size})`);
ok(catalogo.porId.get(1)?.slug === 'lotto-activo', 'porId[1] → lotto-activo');
ok(catalogo.porSlug.get('triple-zulia')?.id === 2, 'porSlug[triple-zulia] → id 2');

console.log('\n== normalizarLabel (NFD, diacríticos, minúsculas) ==');
const casosAcentos = {
  Ciempiés: 'ciempies',
  Cáncer: 'cancer',
  Géminis: 'geminis',
  Delfín: 'delfin',
  Águila: 'aguila',
  Guácharo: 'guacharo',
  'Leoncito (comodín A)': 'leoncito (comodin a)',
};
for (const [entrada, esperado] of Object.entries(casosAcentos)) {
  ok(normalizarLabel(entrada) === esperado, `normalizarLabel('${entrada}') → '${esperado}'`);
}

console.log('\n== REQ-CL-03: desambiguación de numero duplicado (Ballena/Delfín) ==');
const lottoActivo = catalogo.porSlug.get('lotto-activo');
const dup0 = buscarPorDigito(lottoActivo, '0');
ok(dup0.length === 2, `dígito 0 en lotto-activo → 2 coincidencias (${dup0.length})`);
ok(
  dup0.some((o) => o.label === 'Ballena') && dup0.some((o) => o.label === 'Delfín'),
  'dup numero:0 → Ballena y Delfín',
);
ok(dup0.every((o) => o.numero === 0), 'ambas coincidencias con numero 0');

console.log('\n== REQ-KB-06: búsqueda por dígito (multi-dígito y terminal "05") ==');
const terminal = catalogo.porSlug.get('terminal-activo');
const t05 = buscarPorDigito(terminal, '05');
ok(
  t05.length === 1 && t05[0].numero === 5 && t05[0].label === '05',
  `terminal "05" → numero 5, label "05" (${t05.map((o) => o.label).join(',')})`,
);
ok(buscarPorDigito(terminal, '5').length === 0, 'terminal "5" sin padding → 0 coincidencias (exige "05")');
const mono = buscarPorDigito(lottoActivo, '13');
ok(mono.length === 1 && mono[0].label === 'Mono', `animal "13" → Mono (${mono.map((o) => o.label).join(',')})`);
ok(buscarPorDigito(lottoActivo, 'abc').length === 0, 'dígitos no numéricos → 0 coincidencias');

console.log('\n== REQ-KB-06: letter-jump cíclico y sin acentos ==');
const idxC1 = saltarPorLetra(lottoActivo, 'c');
ok(idxC1 === 2 && lottoActivo.opciones[idxC1].label === 'Carnero', `'c' → Carnero (idx ${idxC1})`);
const idxC2 = saltarPorLetra(lottoActivo, 'c', idxC1);
ok(idxC2 === 4 && lottoActivo.opciones[idxC2].label === 'Ciempiés', `'c' repetida → Ciempiés (idx ${idxC2})`);
const idxCWrap = saltarPorLetra(lottoActivo, 'c', 37);
ok(idxCWrap === 2, `cíclico: desde Culebra(37) vuelve a Carnero(2) (idx ${idxCWrap})`);
ok(saltarPorLetra(lottoActivo, 'x') === null, 'letra sin coincidencias → null');
const idxA = saltarPorLetra(lottoActivo, 'á');
ok(idxA === 5 && lottoActivo.opciones[idxA].label === 'Alacrán', `'á' sin acento → Alacrán (idx ${idxA})`);
const zulia = catalogo.porSlug.get('triple-zulia');
const idxZ = saltarPorLetra(zulia, 'c');
ok(idxZ === 3 && zulia.opciones[idxZ].label === 'Cáncer', `zodiacal 'c' → Cáncer (idx ${idxZ})`);

console.log('\n== Salvaguarda Cobra→Cebra (datos legacy sintéticos) ==');
const legado = cargarCatalogo({
  version: 1,
  juegos: [
    {
      id: 99,
      slug: 'legacy',
      nombre: 'Legacy',
      tipo: 'animalitos',
      premio_multiplo: 30,
      comodines: null,
      modalidades: null,
      horarios: ['08:00'],
      opciones: [
        { numero: 1, label: 'Cobra', value: 'cobra' },
        { numero: 2, label: 'Ballena', value: 'ballena' },
      ],
    },
  ],
});
const legacyJuego = legado.porSlug.get('legacy');
ok(legacyJuego.opciones[0].label === 'Cebra', `label Cobra → Cebra (${legacyJuego.opciones[0].label})`);
ok(legacyJuego.opciones[0].value === 'cebra', `value cobra → cebra (${legacyJuego.opciones[0].value})`);
ok(legacyJuego.opciones[1].label === 'Ballena', 'opción sin Cobra no se altera');
ok(buscarPorDigito(legacyJuego, '1').length === 1, 'búsqueda funciona tras salvaguarda');

console.log('\n== Buffer multi-dígito (700ms / Enter) ==');
let resultado = null;
const buscador = crearBuscadorDigitos(terminal, (coincidencias, buffer) => {
  resultado = { coincidencias, buffer };
});
buscador.teclear('0');
buscador.teclear('5');
buscador.commit();
ok(
  resultado && resultado.buffer === '05' && resultado.coincidencias.length === 1 && resultado.coincidencias[0].numero === 5,
  `buffer "05" + commit(Enter) → terminal 05 (${resultado?.coincidencias.map((o) => o.label).join(',') ?? 'sin resultado'})`,
);
resultado = null;
const buscador2 = crearBuscadorDigitos(lottoActivo, (coincidencias, buffer) => {
  resultado = { coincidencias, buffer };
}, 20);
buscador2.teclear('1');
buscador2.teclear('3');
await new Promise((r) => setTimeout(r, 60));
ok(
  resultado && resultado.buffer === '13' && resultado.coincidencias.length === 1 && resultado.coincidencias[0].label === 'Mono',
  `buffer "13" por inactividad (20ms) → Mono (${resultado?.coincidencias.map((o) => o.label).join(',') ?? 'sin resultado'})`,
);
buscador2.limpiar();
ok(buscador2.buffer() === '', 'limpiar() vacía el buffer');
buscador.teclear('x');
ok(buscador.buffer() === '05', 'teclear no numérico se ignora');

console.log('\n== A2: grafo de zonas por familia ==');
const baseEsperada = ['juegos', 'seleccion', 'horarios', 'numero', 'monto', 'anadir', 'resumen'];
const gNumerica = buildZoneGraph('numerica', {});
ok(
  JSON.stringify(gNumerica.zonas) === JSON.stringify(baseEsperada),
  `numérica usa la base (${gNumerica.zonas.join('→')})`,
);
const gTerminal = buildZoneGraph('terminal', {});
ok(
  JSON.stringify(gTerminal.zonas) === JSON.stringify(baseEsperada),
  `terminal usa la base (${gTerminal.zonas.join('→')})`,
);
const gAnimal = buildZoneGraph('animalitos', {});
ok(!gAnimal.incluye('numero'), 'animalitos omite la zona numero (dígitos en seleccion)');
ok(!gAnimal.incluye('signo'), 'animalitos sin zona signo');
ok(
  JSON.stringify(gAnimal.zonas) === JSON.stringify(['juegos', 'seleccion', 'horarios', 'monto', 'anadir', 'resumen']),
  `animalitos: ${gAnimal.zonas.join('→')}`,
);
const gZodSin = buildZoneGraph('zodiacal', {});
ok(
  JSON.stringify(gZodSin.zonas) === JSON.stringify(['juegos', 'modalidad', 'seleccion', 'horarios', 'numero', 'monto', 'anadir', 'resumen']),
  `zodiacal sin triple_c: modalidad tras juegos, sin signo (${gZodSin.zonas.join('→')})`,
);
const gZodCon = buildZoneGraph('zodiacal', { triple_c: true });
ok(
  JSON.stringify(gZodCon.zonas) === JSON.stringify(['juegos', 'modalidad', 'seleccion', 'signo', 'horarios', 'numero', 'monto', 'anadir', 'resumen']),
  `zodiacal con triple_c: zona signo antes de horarios (${gZodCon.zonas.join('→')})`,
);
ok(gZodCon.incluye('signo') && !gZodSin.incluye('signo'), 'signo solo si ctx.triple_c (D1)');
ok(gNumerica.siguiente('resumen') === 'juegos', 'wrap Tab: resumen → juegos');
ok(gNumerica.anterior('juegos') === 'resumen', 'wrap Shift+Tab: juegos → resumen');
ok(gNumerica.siguiente(null) === 'juegos', 'foco inicial: siguiente(null) → juegos');
ok(gAnimal.siguiente('horarios') === 'monto', 'animalitos: horarios → monto (sin numero)');
ok(gZodCon.siguiente('seleccion') === 'signo', 'zodiacal triple_c: seleccion → signo');

// Coherencia con el catálogo bundled: las familias derivadas generan los
// grafos correctos para los juegos reales (KB-02).
const trioActivo = catalogo.porSlug.get('trio-activo');
ok(trioActivo.familia === 'numerica', 'trio-activo → familia numerica');
ok(trioActivo.opciones.length === 100, 'trio-activo → 100 opciones 00-99 (KB-02)');
ok(buildZoneGraph(trioActivo.familia, {}).incluye('numero'), 'numérica 100: incluye zona numero');
const zulia2 = catalogo.porSlug.get('triple-zulia');
ok(zulia2.familia === 'zodiacal' && zulia2.opciones.length === 12, 'triple-zulia → zodiacal 12 signos');

console.log('\n== A1: routeKey — F-keys, guards y repeat ==');
const est = (parcial) => ({
  tecla: 'F5',
  repeat: false,
  ctrlKey: false,
  shiftKey: false,
  altKey: false,
  focoEditable: false,
  zonaActual: 'juegos',
  modalAbierto: false,
  modalPropio: null,
  columnMode: 'juegos',
  tieneLineas: false,
  tieneHistorial: false,
  itemResumenSeleccionado: false,
  seleccionEnCurso: false,
  ...parcial,
});
let r = routeKey(est({ tecla: 'F4', tieneLineas: true }));
ok(r.consume && r.tipo === 'f-key' && r.ejecutable === true, 'F4 con líneas → consume, ejecutable (pagar/generar)');
r = routeKey(est({ tecla: 'F4', tieneLineas: false }));
ok(r.consume && r.tipo === 'f-key' && r.ejecutable === false, 'F4 sin líneas → consume (preventDefault) pero NO ejecuta');
r = routeKey(est({ tecla: 'F11', tieneLineas: false }));
ok(r.consume && r.ejecutable === true, 'F11 sin líneas → ejecutable (limpia la selección en curso)');
r = routeKey(est({ tecla: 'F11', tieneLineas: true }));
ok(r.consume && r.ejecutable === true, 'F11 con líneas → ejecutable');
r = routeKey(est({ tecla: 'F2', tieneLineas: false }));
ok(r.consume && r.tipo === 'f-key' && r.accion === 'ir-numero' && r.ejecutable === true, 'F2 → f-key «ir-numero» ejecutable (atajos-2026-09)');
r = routeKey(est({ tecla: 'F10' }));
ok(r.consume && r.tipo === 'f-key' && r.accion === 'anular-ticket' && r.ejecutable === true, 'F10 → f-key «anular-ticket» ejecutable (sin guarda de líneas)');
r = routeKey(est({ tecla: 'F1', tieneHistorial: false }));
ok(r.consume && r.ejecutable === false, 'F1 sin historial → guarda bloquea (repetir última)');
r = routeKey(est({ tecla: 'F1', tieneHistorial: true }));
ok(r.consume && r.tipo === 'f-key' && r.ejecutable === true, 'F1 con historial → ejecutable');
r = routeKey(est({ tecla: 'F3', tieneHistorial: false }));
ok(r.consume && r.ejecutable === false, 'F3 sin historial → guarda bloquea (eliminar última)');
r = routeKey(est({ tecla: 'F4', repeat: true, tieneLineas: true }));
ok(!r.consume, 'F4 con e.repeat → se ignora (A1)');
r = routeKey(est({ tecla: 'F4', focoEditable: true, tieneLineas: true }));
ok(r.consume && r.tipo === 'f-key' && r.ejecutable, 'F4 en INPUT → sí se intercepta (única excepción F-key)');

console.log('\n== atajos-2026-09 rev.2: «+» = reimprimir (era F9) ==');
r = routeKey(est({ tecla: '+' }));
ok(
  r.consume && r.tipo === 'f-key' && r.fkey === '+' && r.accion === 'reimprimir' && r.ejecutable === true,
  '`+` → f-key «reimprimir» ejecutable',
);
r = routeKey(est({ tecla: '+', repeat: true }));
ok(!r.consume && r.tipo === 'pasar', '`+` con e.repeat → se ignora (A1)');
r = routeKey(est({ tecla: '+', focoEditable: true }));
ok(r.consume && r.tipo === 'f-key' && r.accion === 'reimprimir', '`+` en INPUT → se intercepta (inputs numéricos)');
r = routeKey(est({ tecla: '+', modalAbierto: true }));
ok(!r.consume && r.tipo === 'pasar', '`+` con modal abierto → pasa (guarda KB-08)');

console.log('\n== A1: routeKey — guarda de modal (REQ-KB-08) ==');
r = routeKey(est({ modalAbierto: true, tecla: 'F2', tieneLineas: true }));
ok(!r.consume, 'modal abierto + F2 → pasa (no ejecuta, KB-08)');
r = routeKey(est({ modalAbierto: true, tecla: 'F4', tieneLineas: true }));
ok(!r.consume, 'modal abierto + F4 → pasa');
r = routeKey(est({ modalAbierto: true, tecla: 'Escape' }));
ok(r.consume && r.tipo === 'escape' && r.nivel === 'modal', 'modal abierto + Esc → cierra modal');
r = routeKey(est({ modalAbierto: true, modalPropio: 'ayuda', altKey: true, tecla: 'h' }));
ok(
  r.consume && r.tipo === 'toggle-modal' && r.modal === 'ayuda' && r.ejecutable === true,
  'modal ayuda + Alt+H → toggle propio (atajos-2026-09)',
);
r = routeKey(est({ modalAbierto: true, modalPropio: 'vuelto', tecla: 'F12' }));
ok(
  r.consume && r.tipo === 'toggle-modal' && r.modal === 'vuelto' && r.ejecutable === true,
  'modal vuelto + F12 → toggle propio',
);
r = routeKey(est({ modalAbierto: true, modalPropio: null, tecla: 'F1' }));
ok(!r.consume, 'modal genérico + F1 → pasa (sin toggle propio)');
r = routeKey(est({ modalAbierto: true, tecla: 'Tab' }));
ok(!r.consume, 'modal abierto + Tab → pasa');

console.log('\n== atajos-2026-09: Alt+H = ayuda (toggle + guarda A12) ==');
r = routeKey(est({ altKey: true, tecla: 'h' }));
ok(
  r.consume && r.tipo === 'toggle-modal' && r.modal === 'ayuda' && r.ejecutable === true,
  'Alt+H sin modal → toggle del modal de ayuda',
);
r = routeKey(est({ altKey: true, tecla: 'h', modalAbierto: true, modalPropio: 'ayuda' }));
ok(r.consume && r.ejecutable === true, 'Alt+H con ayuda abierta → cierra (toggle propio)');
r = routeKey(est({ altKey: true, tecla: 'h', modalAbierto: true, modalPropio: 'vuelto' }));
ok(
  r.consume && r.tipo === 'toggle-modal' && r.modal === 'ayuda' && r.ejecutable === false,
  'Alt+H con vuelto abierto → consume pero NO abre ayuda (A12)',
);
r = routeKey(est({ altKey: true, tecla: 'h', modalAbierto: true, modalPropio: null }));
ok(r.consume && r.ejecutable === false, 'Alt+H con modal genérico → consume sin abrir ayuda (A12)');
r = routeKey(est({ altKey: true, tecla: 'h', repeat: true }));
ok(!r.consume, 'Alt+H con e.repeat → no re-dispara el toggle');
r = routeKey(est({ altKey: true, tecla: 'h', focoEditable: true }));
ok(r.consume && r.ejecutable === true, 'Alt+H en INPUT → abre ayuda (como el viejo F1)');
r = routeKey(est({ altKey: true, tecla: 'H' }));
ok(r.consume && r.ejecutable === true, 'Alt+Shift+H (mayúscula) → también toggles ayuda');
r = routeKey(est({ altKey: true, ctrlKey: true, tecla: 'h' }));
ok(!r.consume && r.tipo === 'pasar', 'Ctrl+Alt+H NO toggles ayuda (pasa, AltGr)');

console.log('\n== A1: routeKey — inputs no se secuestran, navegación ==');
r = routeKey(est({ focoEditable: true, tecla: 'a' }));
ok(!r.consume, 'typing en INPUT → pasa (no intercepta)');
r = routeKey(est({ focoEditable: true, tecla: 'Tab' }));
ok(!r.consume, 'Tab en INPUT → pasa (comportamiento nativo)');
r = routeKey(est({ focoEditable: true, tecla: 'Escape' }));
ok(r.consume && r.tipo === 'escape' && r.nivel === 'input', 'Esc en INPUT → blur (consumido)');
r = routeKey(est({ tecla: 'Tab' }));
ok(r.consume && r.tipo === 'tab-siguiente', 'Tab en zona → siguiente zona');
r = routeKey(est({ tecla: 'Tab', shiftKey: true }));
ok(r.consume && r.tipo === 'tab-anterior', 'Shift+Tab → zona anterior');
r = routeKey(est({ zonaActual: 'juegos', tecla: 'ArrowRight' }));
ok(r.consume && r.tipo === 'pestana-siguiente', '→ en zona Juegos → siguiente pestaña');
r = routeKey(est({ zonaActual: 'juegos', tecla: 'ArrowLeft' }));
ok(r.consume && r.tipo === 'pestana-anterior', '← en zona Juegos → pestaña anterior');
r = routeKey(est({ zonaActual: 'seleccion', tecla: 'ArrowRight' }));
ok(r.consume && r.tipo === 'zona-derecha', '→ fuera de Juegos → zona derecha (seleccion → resumen, FIX-3a)');
r = routeKey(est({ zonaActual: 'seleccion', tecla: 'ArrowDown' }));
ok(r.consume && r.tipo === 'fila-siguiente', '↓ en lista → fila siguiente (contextual)');
r = routeKey(est({ zonaActual: 'horarios', tecla: 'ArrowUp' }));
ok(r.consume && r.tipo === 'fila-anterior', '↑ en horarios → fila anterior');
r = routeKey(est({ focoEditable: true, tecla: 'ArrowDown' }));
ok(!r.consume, '↓ en INPUT → pasa (spinner nativo, A1)');
r = routeKey(est({ columnMode: 'horarios', zonaActual: 'horarios', tecla: 'Escape' }));
ok(r.consume && r.tipo === 'escape' && r.nivel === 'juegos', 'Esc en modo horarios → vuelve a Juegos (KB-04)');
r = routeKey(est({ columnMode: 'juegos', zonaActual: 'monto', tecla: 'Escape' }));
ok(r.consume && r.tipo === 'escape' && r.nivel === 'zona-anterior', 'Esc fuera de horarios → zona previa');
r = routeKey(est({ tecla: 'Enter' }));
ok(!r.consume, 'Enter en zona → pasa (semántica de toggle en PR3a)');
r = routeKey(est({ tecla: 'x' }));
ok(!r.consume, 'tecla no mapeada → pasa');

console.log('\n== atajos-2026-09: Backspace = eliminar-item (era F6) ==');
r = routeKey(est({ tecla: 'Backspace', zonaActual: 'resumen', itemResumenSeleccionado: true, tieneLineas: true }));
ok(r.consume && r.tipo === 'eliminar-item', 'Backspace en Resumen con fila enfocada → eliminar-item');
r = routeKey(est({ tecla: 'Backspace', zonaActual: 'resumen', itemResumenSeleccionado: false, tieneLineas: true }));
ok(!r.consume, 'Backspace en Resumen sin fila enfocada → pasa');
r = routeKey(est({ tecla: 'Backspace', zonaActual: 'resumen', itemResumenSeleccionado: true, tieneLineas: false }));
ok(!r.consume, 'Backspace sin líneas → pasa (guarda «lineas»)');
r = routeKey(est({ tecla: 'Backspace', zonaActual: 'juegos', itemResumenSeleccionado: true, tieneLineas: true }));
ok(!r.consume, 'Backspace fuera del Resumen → pasa');
r = routeKey(est({ tecla: 'Backspace', zonaActual: 'resumen', itemResumenSeleccionado: true, tieneLineas: true, focoEditable: true }));
ok(!r.consume, 'Backspace en INPUT → pasa (se sigue escribiendo normal)');
r = routeKey(est({ tecla: 'Backspace', zonaActual: 'resumen', itemResumenSeleccionado: true, tieneLineas: true, modalAbierto: true }));
ok(!r.consume, 'Backspace con modal abierto → pasa (guarda KB-08)');

console.log('\n== PR3a: routeKey — marcar todos (KB-05) ==');
r = routeKey(est({ ctrlKey: true, tecla: 'a', columnMode: 'horarios' }));
ok(r.consume && r.tipo === 'marcar-todos', 'Ctrl+A fuera de input con horarios abiertos → marcar-todos');
r = routeKey(est({ tecla: '*', columnMode: 'horarios' }));
ok(r.consume && r.tipo === 'marcar-todos', '`*` con horarios abiertos → marcar-todos');
r = routeKey(est({ ctrlKey: true, tecla: 'a', columnMode: 'juegos' }));
ok(!r.consume, 'Ctrl+A sin lista de horarios abierta → pasa (no secuestra)');
r = routeKey(est({ tecla: '*', columnMode: 'juegos' }));
ok(!r.consume, '`*` sin lista de horarios abierta → pasa');
r = routeKey(est({ ctrlKey: true, tecla: 'a', focoEditable: true, columnMode: 'horarios' }));
ok(!r.consume, 'Ctrl+A en INPUT → pasa (selección de texto nativa, no secuestra)');
r = routeKey(est({ ctrlKey: true, tecla: 'a', modalAbierto: true }));
ok(!r.consume, 'Ctrl+A con modal abierto → pasa (guarda KB-08)');

console.log('\n== PR3a: multiselección de horarios (KB-05, A3) ==');
let sel = alternarHorario([], '14:00');
ok(JSON.stringify(sel) === JSON.stringify(['14:00']), `toggle: [] + '14:00' → ['14:00'] (${sel.join(',')})`);
sel = alternarHorario(sel, '17:00');
ok(JSON.stringify(sel) === JSON.stringify(['14:00', '17:00']), `toggle: agrega '17:00' → ['14:00','17:00'] (${sel.join(',')})`);
sel = alternarHorario(sel, '14:00');
ok(JSON.stringify(sel) === JSON.stringify(['17:00']), `toggle: quita '14:00' → ['17:00'] (${sel.join(',')})`);
ok(
  JSON.stringify(marcarTodosVisibles(['14:00', '17:00'])) === JSON.stringify(['14:00', '17:00']),
  'marcarTodosVisibles marca todos los visibles (orden preservado)',
);
ok(JSON.stringify(marcarTodosVisibles([])) === JSON.stringify([]), 'marcarTodosVisibles con lista vacía → []');

console.log('\n== PR3a: expansión a N apuestas (REQ-MH-02, REQ-TF-01, D2) ==');
const baseLinea = {
  juegoId: 1,
  juegoName: 'Lotto Activo',
  juegoType: 'animalitos',
  numero: '27',
  animal: 'Perro',
  monto: 10,
  moneda: 'bs',
  tripleModalidad: null,
  tripleModalidadLabel: null,
};
const expandidas = expandirLineas(baseLinea, ['14:00', '17:00'], 'g1');
ok(expandidas.length === 2, `2 horarios ⇒ 2 líneas (${expandidas.length})`);
ok(expandidas.every((l) => l.monto === 10), 'cada línea con monto completo (10)');
ok(expandidas[0].horario === '14:00' && expandidas[1].horario === '17:00', 'línea 1 → 14:00, línea 2 → 17:00');
ok(expandidas.every((l) => l.groupId === 'g1'), 'todas las líneas del grupo con el mismo groupId');
ok(expandidas[0].animal === 'Perro' && expandidas[0].juegoId === 1, 'la base se copia por línea');
ok(expandirLineas(baseLinea, [], 'g2').length === 0, '0 horarios ⇒ 0 líneas');

console.log('\n== PR3a: filtrado dinámico y expiración (REQ-MH-04, A4) ==');
// 2026-09-17T18:30:00Z = 14:30 en America/Caracas (UTC-4, sin DST).
const AHORA_CARACAS = new Date('2026-09-17T18:30:00Z');
ok(ahoraHHMM(AHORA_CARACAS) === '14:30', `ahoraHHMM(18:30Z) → 14:30 Caracas (${ahoraHHMM(AHORA_CARACAS)})`);
ok(horarioExpirado('14:00', AHORA_CARACAS), '14:00 < 14:30 → expirado');
ok(!horarioExpirado('15:00', AHORA_CARACAS), '15:00 > 14:30 → futuro');
ok(horarioExpirado('14:30', AHORA_CARACAS), '14:30 == ahora → expirado (límite)');
const futuros = filtrarHorariosFuturos(['08:00', '14:00', '14:30', '17:00', '22:00'], AHORA_CARACAS);
ok(JSON.stringify(futuros) === JSON.stringify(['17:00', '22:00']), `filtro: solo HH:MM > ahora (${futuros.join(',')})`);
const expiradosSel = seleccionadosExpirados(['14:00', '17:00'], AHORA_CARACAS);
ok(JSON.stringify(expiradosSel) === JSON.stringify(['14:00']), `seleccionadosExpirados → ['14:00'] (${expiradosSel.join(',')})`);
ok(seleccionadosExpirados([], AHORA_CARACAS).length === 0, 'sin selección → sin expirados');

console.log('\n== PR3a: resumen agrupado por jugada (REQ-MH-03, TF-04, A3) ==');
const conGrupos = [
  ...expandirLineas(baseLinea, ['14:00', '17:00'], 'g1'),
  ...expandirLineas(baseLinea, ['18:00'], 'g2'),
];
const grupos = agruparPorGroupId(conGrupos);
ok(grupos.length === 2, `2 grupos (${grupos.length})`);
ok(grupos[0].groupId === 'g1' && grupos[0].lines.length === 2, 'g1 con 2 líneas (14:00, 17:00)');
ok(grupos[1].groupId === 'g2' && grupos[1].lines.length === 1, 'g2 con 1 línea (18:00)');
ok(
  JSON.stringify(grupos[0].lines.map((l) => l.horario)) === JSON.stringify(['14:00', '17:00']),
  'orden de horarios preservado en el grupo',
);
ok(JSON.stringify(agruparPorGroupId([])) === JSON.stringify([]), 'sin líneas → sin grupos');

console.log('\n== atajos-2026-09: KEYMAP F1–F12 ==');
ok(KEYMAP.length === 12, `KEYMAP: 12 teclas (${KEYMAP.length})`);
ok(esFKey('F1') && esFKey('F12') && esFKey('F9'), 'esFKey F1/F12/F9 → true');
ok(!esFKey('F13') && !esFKey('f1') && !esFKey('Enter') && !esFKey('Backspace'), 'esFKey no-F → false');
const accionDe = (tecla) => KEYMAP.find((k) => k.tecla === tecla)?.accion;
ok(
  accionDe('F1') === 'repetir-ultima' && accionDe('F2') === 'ir-numero' &&
    accionDe('F3') === 'anular-ultima' && accionDe('F4') === 'pagar-generar',
  'F1–F4: repetir/números/eliminar-última/pagar',
);
ok(
  accionDe('F5') === 'ventas' && accionDe('F6') === 'resultados' &&
    accionDe('F7') === 'ganadores' && accionDe('F8') === 'cuadre',
  'F5–F8: navegación global (ventas/resultados/ganadores/cuadre)',
);
ok(
  accionDe('F9') === null && accionDe('F10') === 'anular-ticket' &&
    accionDe('F11') === 'limpiar' && accionDe('F12') === 'vuelto',
  'F9 libre (reimprimir pasó a «+»); F10–F12: locales del dashboard',
);
const acciones = KEYMAP.map((k) => k.accion).filter(Boolean);
ok(acciones.length === 11, `11 acciones mapeadas (F9 sin acción; resto de F1–F12, ${acciones.length})`);
ok(KEYMAP.every((k) => k.implementadaEn === 'atajos-2026-09'), 'todo el KEYMAP marcado como batch «atajos-2026-09»');
ok(KEYMAP.find((k) => k.tecla === 'F2')?.nombre === 'Números', 'F2 → «Números» en el KEYMAP');
ok(!KEYMAP.some((k) => k.accion === 'ayuda'), '«ayuda» ya no es F-key: pasa al combo Alt+H');
ok(
  KEYMAP.filter((k) => k.guarda === 'lineas').map((k) => k.tecla).join(',') === 'F4',
  'guarda de líneas: solo F4 (pagar/generar)',
);
ok(
  KEYMAP.filter((k) => k.guarda === 'historial').map((k) => k.tecla).join(',') === 'F1,F3',
  'guarda de historial: F1/F3 (repetir/eliminar última)',
);
ok(KEYMAP.filter((k) => k.guarda === 'ninguno').length === 9, 'sin guarda: 9 teclas (F2, F5–F12)');

console.log('\n== atajos-2026-09: F11 «Limpiar todo» (reset total, ex F2) ==');
const f11 = KEYMAP.find((k) => k.tecla === 'F11');
ok(f11.nombre === 'Limpiar todo', `F11 nombre → «Limpiar todo» (${f11.nombre})`);
ok(f11.guarda === 'ninguno', 'F11 sin guarda de líneas (se ejecuta con selección en curso)');
ok(teclasLegend().find((t) => t.tecla === 'F11')?.nombre === 'Limpiar todo', 'leyenda refleja «Limpiar todo»');
r = routeKey(est({ tecla: 'F11', tieneLineas: false, seleccionEnCurso: true }));
ok(r.consume && r.tipo === 'f-key' && r.ejecutable === true, 'F11 con selección en curso y 0 líneas → ejecuta (limpia todo)');

console.log('\n== PR3b: calcularVuelto (A12, REQ-KB-07 F9) ==');
const cerca = (a, b) => Math.abs(a - b) < 1e-9;

// Positivo en Bs (vuelto a devolver) + equivalente en $ con tasa disponible.
let v = calcularVuelto({ totalBs: 100, recibido: 150, moneda: 'bs', tasa: 36.5 });
ok(v.ok === true, 'vuelto Bs: cálculo disponible');
ok(cerca(v.vueltoBs, 50), `positivo Bs: vuelto 50 (${v.vueltoBs})`);
ok(v.negativo === false, 'positivo → no es falta');
ok(v.vueltoUsd !== null && cerca(v.vueltoUsd, 50 / 36.5), `equivalente $: 50/36.5 (${v.vueltoUsd})`);

// Negativo en Bs (falta) → vuelto negativo, marcado como falta.
v = calcularVuelto({ totalBs: 100, recibido: 80, moneda: 'bs', tasa: 36.5 });
ok(v.ok === true && cerca(v.vueltoBs, -20) && v.negativo === true, `negativo Bs: falta 20 (${v.vueltoBs})`);

// Pago exacto → vuelto 0, no es falta.
v = calcularVuelto({ totalBs: 100, recibido: 100, moneda: 'bs', tasa: 36.5 });
ok(v.ok === true && cerca(v.vueltoBs, 0) && v.negativo === false, 'pago exacto → vuelto 0');

// Recibido en USD: se convierte con la tasa (5 × 36.5 = 182.5 Bs → vuelto 82.5).
v = calcularVuelto({ totalBs: 100, recibido: 5, moneda: 'usd', tasa: 36.5 });
ok(v.ok === true, 'USD con tasa → cálculo disponible');
ok(cerca(v.vueltoBs, 82.5), `USD: vuelto 82.5 Bs (${v.vueltoBs})`);
ok(v.vueltoUsd !== null && cerca(v.vueltoUsd, 82.5 / 36.5), `USD: equivalente $ (${v.vueltoUsd})`);

// Tasa no disponible: NUNCA tasa silenciosa (A12).
v = calcularVuelto({ totalBs: 100, recibido: 5, moneda: 'usd', tasa: null });
ok(v.ok === false && v.motivo === 'tasa-no-disponible', 'USD sin tasa → tasa-no-disponible (sin tasa silenciosa)');
v = calcularVuelto({ totalBs: 100, recibido: 5, moneda: 'usd', tasa: 0 });
ok(v.ok === false && v.motivo === 'tasa-no-disponible', 'USD con tasa 0 → tasa-no-disponible');
v = calcularVuelto({ totalBs: 100, recibido: 5, moneda: 'usd', tasa: -1 });
ok(v.ok === false && v.motivo === 'tasa-no-disponible', 'USD con tasa negativa → tasa-no-disponible');
v = calcularVuelto({ totalBs: 100, recibido: 120, moneda: 'bs', tasa: null });
ok(v.ok === true && cerca(v.vueltoBs, 20) && v.vueltoUsd === null, 'Bs sin tasa → calcula en Bs (sin equivalente $)');

console.log('\n== Backspace (era F6): selección del resumen tras eliminar (KB-07) ==');
ok(indiceSeleccionTrasEliminar(1, 0) === null, '1 grupo, elimino el único → sin selección (null)');
ok(indiceSeleccionTrasEliminar(2, 0) === 0, '2 grupos, elimino el 1º → queda la fila en índice 0');
ok(indiceSeleccionTrasEliminar(2, 1) === 0, '2 grupos, elimino el último → selecciona la 1ª restante (0)');
ok(indiceSeleccionTrasEliminar(3, 1) === 1, '3 grupos, elimino el medio → la fila que ocupó su lugar (1)');
ok(indiceSeleccionTrasEliminar(3, 2) === 1, '3 grupos, elimino el último → selecciona la última restante (1)');
ok(indiceSeleccionTrasEliminar(4, 3) === 2, '4 grupos, elimino el último → última restante (2)');
ok(indiceSeleccionTrasEliminar(0, 0) === null, '0 grupos → null (sin filas)');

console.log('\n== win-fixes FIX-3a: ruteo lateral ←/→ entre columnas ==');
ok(zonaHorizontal('juegos', 'derecha', 'juegos') === 'seleccion', 'juegos → seleccion (derecha)');
ok(zonaHorizontal('horarios', 'derecha', 'horarios') === 'seleccion', 'horarios → seleccion (derecha)');
ok(zonaHorizontal('seleccion', 'derecha', 'juegos') === 'resumen', 'seleccion → resumen (derecha)');
ok(zonaHorizontal('seleccion', 'izquierda', 'juegos') === 'juegos', 'seleccion ← juegos (izquierda, columnMode juegos)');
ok(zonaHorizontal('seleccion', 'izquierda', 'horarios') === 'horarios', 'seleccion ← horarios (izquierda, columnMode horarios)');
ok(zonaHorizontal('modalidad', 'izquierda', 'horarios') === 'horarios', 'modalidad ← horarios (centro → izquierda)');
ok(zonaHorizontal('signo', 'derecha', 'juegos') === 'resumen', 'signo → resumen (centro → derecha)');
ok(zonaHorizontal('resumen', 'izquierda', 'juegos') === 'seleccion', 'resumen ← seleccion (izquierda)');
ok(zonaHorizontal('numero', 'derecha', 'juegos') === null, 'numero sin vecino horizontal (barra superior)');
ok(zonaHorizontal('monto', 'izquierda', 'juegos') === null, 'monto sin vecino horizontal');
ok(zonaHorizontal('anadir', 'derecha', 'juegos') === null, 'anadir sin vecino horizontal');
ok(zonaHorizontal('horarios', 'izquierda', 'horarios') === null, 'horarios es la columna izquierda: ← sin vecino');
r = routeKey(est({ zonaActual: 'seleccion', tecla: 'ArrowLeft' }));
ok(r.consume && r.tipo === 'zona-izquierda', '← en seleccion → zona-izquierda (FIX-3a)');
r = routeKey(est({ zonaActual: 'horarios', columnMode: 'horarios', tecla: 'ArrowRight' }));
ok(r.consume && r.tipo === 'zona-derecha', '→ en horarios → zona-derecha (horarios ↔ seleccion, FIX-3a)');
r = routeKey(est({ zonaActual: 'horarios', columnMode: 'horarios', tecla: 'ArrowLeft' }));
ok(!r.consume, '← en horarios (columna izquierda) → pasa');
r = routeKey(est({ zonaActual: 'numero', tecla: 'ArrowRight' }));
ok(!r.consume, '→ en numero (sin vecino) → pasa');

console.log('\n== win-fixes FIX-3b: zona de selección pendiente (Tab desde Horarios) ==');
const pend = (familia, tripleModalidad = null, signoElegido = false, animalElegido = false) =>
  zonaPendienteSeleccion({ familia, tripleModalidad, signoElegido, animalElegido });
ok(pend('animalitos') === 'seleccion', 'animalitos sin animal → pendiente seleccion');
ok(pend('animalitos', null, false, true) === null, 'animalitos con animal → sin pendiente');
ok(pend('zodiacal') === 'modalidad', 'zodiacal sin modalidad → pendiente modalidad');
ok(pend('zodiacal', 'triple_c') === 'signo', 'zodiacal triple_c sin signo → pendiente signo');
ok(pend('zodiacal', 'triple_c', true) === null, 'zodiacal triple_c con signo → sin pendiente');
ok(pend('zodiacal', 'triple_a') === null, 'zodiacal triple_a → sin pendiente');
ok(pend('numerica', 'triple_a') === null, 'numérica → sin pendiente (el número es la zona numero)');
ok(pend('terminal') === null, 'terminal → sin pendiente');
ok(pend(null) === null, 'sin juego → sin pendiente');

console.log('\n== win-fixes FIX-3c: guard de pestañas con selección en curso ==');
r = routeKey(est({ zonaActual: 'juegos', tecla: 'ArrowRight', seleccionEnCurso: true }));
ok(r.consume && r.tipo === 'pestana-siguiente' && r.ejecutable === false, '→ en Juegos con selección en curso → pestaña BLOQUEADA (FIX-3c)');
r = routeKey(est({ zonaActual: 'juegos', tecla: 'ArrowRight', seleccionEnCurso: false }));
ok(r.consume && r.tipo === 'pestana-siguiente' && r.ejecutable === true, '→ en Juegos sin selección → pestaña ejecutable');
r = routeKey(est({ zonaActual: 'juegos', tecla: 'ArrowLeft', seleccionEnCurso: true }));
ok(r.consume && r.tipo === 'pestana-anterior' && r.ejecutable === false, '← en Juegos con selección en curso → pestaña BLOQUEADA');

console.log('\n== feat/taquilla-logos-juegos: grid 2 columnas (gridNav) ==');
// ←/→: celda adyacente de la MISMA fila (columna ±1, sin wrap). null en el
// borde → el glue cae al cambio de pestaña. idx === -1 (contenedor) → null.
ok(indiceDestinoColumna(0, 4, 1) === 1, 'col 0 → 1 en la misma fila');
ok(indiceDestinoColumna(1, 4, 1) === null, 'col 1 → borde derecho (null)');
ok(indiceDestinoColumna(1, 4, -1) === 0, 'col 1 ← 0 en la misma fila');
ok(indiceDestinoColumna(0, 4, -1) === null, 'col 0 ← borde izquierdo (null)');
ok(indiceDestinoColumna(2, 4, 1) === 3, 'fila 2: col 0 → 1');
ok(indiceDestinoColumna(2, 4, -1) === null, 'fila 2: ← borde izquierdo (null)');
ok(indiceDestinoColumna(3, 4, 1) === null, 'fila 2: → borde derecho (null)');
// Fila impar (última con 1 solo ítem en col 0): ambos lados son borde.
ok(indiceDestinoColumna(4, 5, 1) === null, 'fila impar: → borde (null)');
ok(indiceDestinoColumna(4, 5, -1) === null, 'fila impar: ← borde (sin wrap a la fila previa)');
ok(indiceDestinoColumna(3, 5, 1) === null, 'col 1 con fila impar debajo: → borde (null)');
// idx inválido / contenedor / total trivial → null (comportamiento de pestaña).
ok(indiceDestinoColumna(-1, 4, 1) === null, 'idx -1 (contenedor) → null (pestaña)');
ok(indiceDestinoColumna(-2, 4, 1) === null, 'idx negativo fuera de rango → null');
ok(indiceDestinoColumna(9, 4, 1) === null, 'idx >= total → null');
ok(indiceDestinoColumna(0, 0, 1) === null, 'total 0 → null');
ok(indiceDestinoColumna(0, 1, 1) === null && indiceDestinoColumna(0, 1, -1) === null, 'total 1: ambos lados borde');
ok(indiceDestinoColumna(0, 6, 1, 3) === 1, 'columnas=3: col 0 → 1');
ok(indiceDestinoColumna(2, 6, 1, 3) === null, 'columnas=3: col 2 → borde (null)');
// indiceDestinoFila (helper movido a gridNav, mismo contrato que el inline).
ok(indiceDestinoFila(0, 4, 1, 2) === 2 && indiceDestinoFila(1, 4, 1, 2) === 3, '↓ salta ±2 conservando la columna');
ok(indiceDestinoFila(0, 4, -1, 2) === null && indiceDestinoFila(1, 4, -1, 2) === null, '↑ en la fila 0 → clamp (null)');
ok(indiceDestinoFila(3, 5, 1, 2) === null, '↓ desde col 1 sin celda en la fila impar → null (no cambia de columna)');
ok(indiceDestinoFila(4, 5, -1, 2) === 2 && indiceDestinoFila(4, 5, 1, 2) === null, 'fila impar: ↑ conserva columna (2), ↓ borde (null)');
ok(indiceDestinoFila(-1, 4, 1, 2) === 0 && indiceDestinoFila(-1, 4, -1, 2) === 3, 'contenedor: entra por el extremo según la dirección');
ok(indiceDestinoFila(0, 0, 1, 2) === null, 'total 0 → null');
ok(indiceDestinoFila(2, 10, 1, 1) === 3, 'paso 1 (listas simples) → ±1');

console.log('\n== fix/taquilla-fixes: grilla 2 columnas de modalidades ==');
// 4 modalidades → 2 filas × 2 columnas: ↑/↓ salta ±2 conservando la columna;
// ←/→ mueve la celda de la MISMA fila y en el borde devuelve null (el glue
// cae al cambio de zona: juegos/horarios o resumen).
ok(indiceDestinoFila(0, 4, 1, 2) === 2 && indiceDestinoFila(1, 4, 1, 2) === 3, 'modalidades ↓: 0→2 y 1→3');
ok(indiceDestinoFila(2, 4, -1, 2) === 0 && indiceDestinoFila(3, 4, -1, 2) === 1, 'modalidades ↑: 2→0 y 3→1');
ok(indiceDestinoFila(2, 4, 1, 2) === null && indiceDestinoFila(3, 4, 1, 2) === null, 'modalidades ↓ en la última fila → null');
ok(indiceDestinoColumna(0, 4, 1) === 1 && indiceDestinoColumna(1, 4, -1) === 0, 'modalidades →/← dentro de la fila 1');
ok(indiceDestinoColumna(2, 4, 1) === 3 && indiceDestinoColumna(3, 4, -1) === 2, 'modalidades →/← dentro de la fila 2');
ok(indiceDestinoColumna(1, 4, 1) === null && indiceDestinoColumna(3, 4, 1) === null, 'modalidades → en el borde derecho → null (cambio de zona)');
ok(indiceDestinoColumna(0, 4, -1) === null && indiceDestinoColumna(2, 4, -1) === null, 'modalidades ← en el borde izquierdo → null (cambio de zona)');
ok(indiceDestinoFila(0, 3, 1, 2) === 2, 'modalidades impares (3): ↓ 0→2 conserva la columna');
ok(indiceDestinoFila(1, 3, 1, 2) === null, 'modalidades impares (3): ↓ 1→null sin cambiar de columna');

console.log('\n== win-fixes FIX-5: ruteo de dígitos en la zona Selección ==');
r = routeKey(est({ zonaActual: 'seleccion', tecla: '5' }));
ok(r.consume && r.tipo === 'digito' && r.digito === '5', 'dígito en seleccion → decisión digito');
r = routeKey(est({ zonaActual: 'seleccion', tecla: '0' }));
ok(r.consume && r.tipo === 'digito' && r.digito === '0', 'dígito 0 en seleccion → digito (dup Ballena/Delfín lo resuelve el glue)');
r = routeKey(est({ zonaActual: 'seleccion', tecla: 'a' }));
ok(!r.consume, 'letra en seleccion → pasa (letter-jump es del glue, no del router)');
r = routeKey(est({ zonaActual: 'juegos', tecla: '5' }));
ok(!r.consume, 'dígito fuera de seleccion → pasa');
r = routeKey(est({ zonaActual: 'seleccion', tecla: '5', focoEditable: true }));
ok(!r.consume, 'dígito en INPUT → pasa (typing nativo, A1)');
r = routeKey(est({ zonaActual: 'seleccion', tecla: '5', modalAbierto: true }));
ok(!r.consume, 'dígito con modal abierto → pasa (guarda KB-08)');

console.log('\n== win-fixes2 FIX B: dígitos 1-12 → signo por posición (zodiacal) ==');
const zuliaSignos = catalogo.porSlug.get('triple-zulia');
ok(signoPorPosicion(zuliaSignos, '1')?.label === 'Aries', 'dígito 1 → Aries (posición 1)');
ok(signoPorPosicion(zuliaSignos, '9')?.value === 'SAG', 'dígito 9 → Sagitario (sigla SAG)');
ok(signoPorPosicion(zuliaSignos, '10')?.label === 'Capricornio', 'dígito 10 → Capricornio (posición 10)');
ok(signoPorPosicion(zuliaSignos, '12')?.value === 'PIS', 'dígito 12 → Piscis (sigla PIS)');
ok(signoPorPosicion(zuliaSignos, '0') === null, 'dígito 0 → null (fuera de 1-12)');
ok(signoPorPosicion(zuliaSignos, '13') === null, 'dígito 13 → null (fuera de 1-12)');
ok(signoPorPosicion(zuliaSignos, 'abc') === null, 'no numérico → null');
ok(signoPorPosicion(zuliaSignos, '') === null, 'buffer vacío → null');
ok(signoPorPosicion(lottoActivo, '5') === null, 'familia no zodiacal → null');
ok(zuliaSignos.opciones.length === 12, 'los 12 signos siguen presentes (posición = índice + 1)');
r = routeKey(est({ zonaActual: 'signo', tecla: '5' }));
ok(r.consume && r.tipo === 'digito' && r.digito === '5', 'dígito en zona Signo → decisión digito (FIX B)');
r = routeKey(est({ zonaActual: 'signo', tecla: '1' }));
ok(r.consume && r.tipo === 'digito' && r.digito === '1', 'dígito 1 en zona Signo → digito');
r = routeKey(est({ zonaActual: 'signo', tecla: 'a' }));
ok(!r.consume, 'letra en zona Signo → pasa (no es dígito)');
r = routeKey(est({ zonaActual: 'signo', tecla: '5', focoEditable: true }));
ok(!r.consume, 'dígito en INPUT dentro de Signo → pasa (typing nativo, A1)');
r = routeKey(est({ zonaActual: 'signo', tecla: '5', modalAbierto: true }));
ok(!r.consume, 'dígito con modal abierto → pasa (guarda KB-08)');

console.log('\n== win-fixes2 FIX D: tripletas numéricas normalizadas a 3 cifras ==');
ok(normalizarNumeroTriple('05') === '005', '"05" → "005" (padding del plugin Tripletas)');
ok(normalizarNumeroTriple('5') === '005', '"5" → "005" (1 cifra)');
ok(normalizarNumeroTriple('005') === '005', '"005" → "005" (ya 3 cifras, sin tocar)');
ok(normalizarNumeroTriple('00') === '000', '"00" → "000"');
ok(normalizarNumeroTriple('0') === '000', '"0" → "000"');
ok(normalizarNumeroTriple('999') === '999', '"999" → "999" (máximo)');
ok(normalizarNumeroTriple('') === null, 'vacío → null');
ok(normalizarNumeroTriple('abc') === null, 'no numérico → null');
ok(normalizarNumeroTriple('1234') === null, '4 cifras → null (fuera del rango del plugin)');
ok(normalizarNumeroTriple(' 05 ') === '005', 'con espacios alrededor se recorta');
// Coherencia con el catálogo: trio-activo/triple-facil son numérica 00-99
// (el plugin Tripletas valida 3 cifras, Tripletas.php:36-47).
const trioNum = catalogo.porSlug.get('trio-activo');
ok(trioNum.familia === 'numerica', 'trio-activo sigue siendo numérica (100 opciones)');
ok(trioNum.modalidades && trioNum.modalidades.punta === 60 && trioNum.modalidades.terminal === 60, 'trio-activo: premios informativos punta/terminal 60× (A2)');
ok(normalizarNumeroTriple(trioNum.opciones[5].label) === '005', `opción label "05" → "005" (${trioNum.opciones[5].label})`);

console.log('\n== win-fixes2 FIX E: signo → sigla (value) en todos los zodiacales ==');
// Mismo conjunto que el plugin Tripletas (Tripletas.php:10-13).
const SIGLAS_TRIPLETAS = ['ARI', 'TAU', 'GEM', 'CAN', 'LEO', 'VIR', 'LIB', 'ESC', 'SAG', 'CAP', 'ACU', 'PIS'];
const zodiacales = ['triple-zulia', 'triple-caliente', 'triple-chance', 'el-arrejuntado', 'triple-tachira', 'triple-zamorano'];
for (const slug of zodiacales) {
  const juego = catalogo.porSlug.get(slug);
  ok(juego.familia === 'zodiacal' && juego.opciones.length === 12, `${slug}: zodiacal con 12 signos`);
  const todosValidos = juego.opciones.every((o) => SIGLAS_TRIPLETAS.includes(siglaDeSigno(juego, o.label)));
  ok(todosValidos, `${slug}: cada label mapea a una sigla válida del plugin`);
  ok(juego.opciones.every((o) => siglaDeSigno(juego, o.label) === o.value), `${slug}: siglaDeSigno(label) === value`);
}
// la-ricachona (motor-premios): inactiva y no vendible → el export no trae
// opciones ni premios; el catálogo la conserva (21 juegos) pero la UI la filtra.
const ricachona = catalogo.porSlug.get('la-ricachona');
ok(ricachona.familia === 'zodiacal' && ricachona.opciones.length === 0, 'la-ricachona: inactiva, sin opciones (zodiacal por tipo)');
ok(ricachona.active === false && ricachona.vendible === false, 'la-ricachona: active=false/vendible=false (motor-premios)');
ok(ricachona.premio_multiplo === null, 'la-ricachona: premio_multiplo null (motor-premios)');

console.log('\n== S2 TQ-06: premios normalizados del catálogo (D1) ==');
// D1: `catalogo.ts` expone `premios {base, modalidades, comodines}` normalizado
// ([]/null → vacío). Los espejos legacy top-level (`cola`, `zodiacal`, `signo`,
// `triple_a_o_b`…) NO son la fuente canónica de modalidades (design §3.1).
const VACIO = { base: null, modalidades: {}, comodines: [] };
ok(JSON.stringify(normalizarPremios(null)) === JSON.stringify(VACIO), 'normalizarPremios(null) → vacío');
ok(JSON.stringify(normalizarPremios([])) === JSON.stringify(VACIO), 'normalizarPremios([]) → vacío');
ok(JSON.stringify(normalizarPremios(undefined)) === JSON.stringify(VACIO), 'normalizarPremios(undefined) → vacío (legado sin premios)');
ok(
  JSON.stringify(normalizarPremios({ base: 30, modalidades: { punta: 60 }, comodines: [] })) ===
    JSON.stringify({ base: 30, modalidades: { punta: 60 }, comodines: [] }),
  'normalizarPremios: objeto canónico pasa intacto',
);
ok(
  JSON.stringify(normalizarPremios({ base: 30, modalidades: [], comodines: [] }).modalidades) === '{}',
  'normalizarPremios: modalidades [] → {}',
);
ok(premios(lottoActivo).base === 30 && premios(lottoActivo).base === lottoActivo.premio_multiplo, 'lotto-activo: premios(juego).base 30× (espejo de premio_multiplo)');
const conBase = catalogo.juegos.filter((j) => typeof premios(j).base === 'number' && premios(j).base > 0).length;
ok(conBase === 20, `20/21 juegos con premios.base no vacío (${conBase})`);
ok(premios(ricachona).base === null && Object.keys(premios(ricachona).modalidades).length === 0, 'la-ricachona: premios normalizados vacíos (base null)');
ok(ricachona.vendible === false && ricachona.premios === null, 'la-ricachona: vendible=false y premios crudo null (D1)');
const zuliaPremios = premios(catalogo.porSlug.get('triple-zulia'));
ok(
  JSON.stringify(modalidadesDe(catalogo.porSlug.get('triple-zulia'))) === JSON.stringify(['terminal', 'signo_triple', 'signo_terminal']),
  'modalidadesDe(zulia) → claves canónicas [terminal, signo_triple, signo_terminal]',
);
ok(zuliaPremios.modalidades.terminal === 60 && zuliaPremios.modalidades.signo_triple === 6000 && zuliaPremios.modalidades.signo_terminal === 600, 'zulia: multiplicadores canónicos desde premios.modalidades');
ok(!('cola' in zuliaPremios.modalidades) && !('zodiacal' in zuliaPremios.modalidades), 'zulia: NO expone espejos legacy (cola/zodiacal)');
const chanceMods = modalidadesDe(catalogo.porSlug.get('triple-chance'));
ok(
  chanceMods.includes('signo_solo') && chanceMods.includes('triple_a_b') &&
    !chanceMods.includes('signo') && !chanceMods.includes('triple_a_o_b') && !chanceMods.includes('triple_c_signo'),
  'chance: claves canónicas (signo_solo/triple_a_b) sin espejos legacy (signo/triple_a_o_b/triple_c_signo)',
);
ok(modalidadesDe(catalogo.porSlug.get('lotto-activo')).length === 0, 'lotto-activo: sin modalidades → []');
const monje = catalogo.porSlug.get('monje-millonario');
ok(modalidadesDe(monje).length === 0 && Object.keys(premios(monje).modalidades).length === 0, 'monje: premios.modalidades crudo [] → {} (modalidadesDe [])');
ok(
  premios(monje).comodines && typeof premios(monje).comodines === 'object' && !Array.isArray(premios(monje).comodines) && 'patronus-75' in premios(monje).comodines,
  'monje: comodines objeto canónico conservado (patronus-75)',
);
ok(siglaDeSigno(catalogo.porSlug.get('triple-zulia'), 'Sagitario') === 'SAG', 'triple-zulia "Sagitario" → SAG');
ok(siglaDeSigno(catalogo.porSlug.get('el-arrejuntado'), 'Aries') === 'ARI', 'el-arrejuntado "Aries" → ARI (cubierto)');
ok(siglaDeSigno(catalogo.porSlug.get('triple-zulia'), 'Inexistente') === null, 'label inexistente → null');
ok(siglaDeSigno(lottoActivo, 'Perro') === null, 'familia no zodiacal → null');
ok(catalogo.porSlug.get('triple-zulia').opciones.every((o) => /^[A-Z]{3}$/.test(o.value)), 'valores zodiacales: siglas de 3 letras mayúsculas');

console.log('\n== atajos-2026-09: navegación global F5–F8 y Alt+D ==');
ok(NAV_GLOBAL.length === 5, `NAV_GLOBAL: 5 destinos (${NAV_GLOBAL.length})`);
ok(NAV_GLOBAL.find((n) => n.tecla === 'F5')?.ruta === '/historial', 'F5 → /historial');
ok(NAV_GLOBAL.find((n) => n.tecla === 'F6')?.ruta === '/resultados', 'F6 → /resultados');
ok(NAV_GLOBAL.find((n) => n.tecla === 'F7')?.ruta === '/ganadores', 'F7 → /ganadores');
ok(NAV_GLOBAL.find((n) => n.tecla === 'F8')?.ruta === '/cierre', 'F8 → /cierre');
ok(NAV_GLOBAL.find((n) => n.tecla === 'Alt+D')?.ruta === '/dashboard', 'Alt+D → /dashboard');
ok(destinoNav('F5', { altKey: false, ctrlKey: false })?.ruta === '/historial', 'destinoNav F5 → /historial');
ok(destinoNav('F6', { altKey: false, ctrlKey: false })?.ruta === '/resultados', 'destinoNav F6 → /resultados');
ok(destinoNav('F7', { altKey: false, ctrlKey: false })?.ruta === '/ganadores', 'destinoNav F7 → /ganadores');
ok(destinoNav('F8', { altKey: false, ctrlKey: false })?.ruta === '/cierre', 'destinoNav F8 → /cierre');
ok(destinoNav('d', { altKey: true, ctrlKey: false })?.ruta === '/dashboard', 'destinoNav Alt+D → /dashboard');
ok(destinoNav('D', { altKey: true, ctrlKey: false })?.ruta === '/dashboard', 'destinoNav Alt+Shift+D (mayúscula) → /dashboard');
ok(destinoNav('d', { altKey: false, ctrlKey: false }) === null, 'd sin Alt → sin destino');
ok(destinoNav('d', { altKey: true, ctrlKey: true }) === null, 'Ctrl+Alt+d (AltGr) → sin destino');
ok(destinoNav('F10', { altKey: false, ctrlKey: false }) === null, 'F10 → sin destino (local: anular ticket)');
ok(destinoNav('F9', { altKey: false, ctrlKey: false }) === null, 'F9 → sin destino (F9 libre)');
ok(destinoNav('F12', { altKey: false, ctrlKey: false }) === null, 'F12 → sin destino (local: vuelto)');
ok(destinoNav('x', { altKey: false, ctrlKey: false }) === null, 'tecla no navegable → null');
const legend = teclasLegend();
ok(legend.length === 15, `teclasLegend: 15 teclas (F1–F12 + Alt+D + Alt+H + «+») (${legend.length})`);
ok(legend.some((t) => t.tecla === 'Alt+D' && t.nombre === 'Dashboard'), 'teclasLegend incluye Alt+D → Dashboard');
ok(legend.some((t) => t.tecla === 'Alt+H' && t.nombre === 'Ayuda'), 'teclasLegend incluye Alt+H → Ayuda (era F1)');
ok(legend.some((t) => t.tecla === '+' && t.nombre === 'Reimprimir'), 'teclasLegend incluye «+» → Reimprimir (era F9)');
ok(legend.find((t) => t.tecla === 'F9')?.nombre === 'Libre', 'teclasLegend muestra F9 como «Libre»');
ok(legend.find((t) => t.tecla === 'F1')?.nombre === 'Repetir última', 'F1 → Repetir última en la leyenda');
ok(legend.find((t) => t.tecla === 'F2')?.nombre === 'Números', 'F2 → Números en la leyenda');
ok(legend.find((t) => t.tecla === 'F4')?.nombre === 'Pagar / Generar', 'F4 → Pagar / Generar en la leyenda');
ok(legend.find((t) => t.tecla === 'F10')?.nombre === 'Anular ticket', 'F10 → Anular ticket en la leyenda');
ok(legend.find((t) => t.tecla === 'F11')?.nombre === 'Limpiar todo', 'F11 → Limpiar todo en la leyenda');
ok(legend.some((t) => t.nombre === 'Números'), 'la leyenda lista «Números» (F2 recupera ir-numero)');
ok(
  ATAJOS_EXTRA.length === 2 &&
    ATAJOS_EXTRA[0].tecla === 'Alt+H' && ATAJOS_EXTRA[0].nombre === 'Ayuda' &&
    ATAJOS_EXTRA[1].tecla === '+' && ATAJOS_EXTRA[1].nombre === 'Reimprimir',
  'ATAJOS_EXTRA: Alt+H → Ayuda y «+» → Reimprimir',
);

console.log('\n== Logos de juegos (feat/taquilla-logos-juegos) ==');
// Artefacto estático del dashboard: mapa slug → archivo generado desde
// public/images/juegos/manifest.json (sin fetch en runtime). Todo slug del
// catálogo debe tener entrada Y el archivo listado debe existir en disco.
const rutaLogos = join(AQUI, '..', 'src', 'data', 'logos.json');
const rutaImagenes = join(AQUI, '..', 'public', 'images', 'juegos');
const logos = JSON.parse(readFileSync(rutaLogos, 'utf8'));
ok(typeof logos === 'object' && logos !== null && !Array.isArray(logos), 'logos.json es un mapa slug → archivo');
const slugsCatalogo = new Set(catalogo.juegos.map((j) => j.slug));
const sinLogo = catalogo.juegos.filter((j) => !logos[j.slug]);
ok(
  sinLogo.length === 0,
  `cobertura: ${catalogo.juegos.length - sinLogo.length}/${catalogo.juegos.length} juegos con logo` +
    (sinLogo.length ? ` (faltan: ${sinLogo.map((j) => j.slug).join(', ')})` : ''),
);
for (const juego of catalogo.juegos) {
  const archivo = logos[juego.slug];
  ok(
    Boolean(archivo) && existsSync(join(rutaImagenes, archivo)),
    `${juego.slug} → ${archivo ?? 'SIN ENTRADA'} presente en public/images/juegos/`,
  );
}
const huerfanos = Object.keys(logos).filter((slug) => !slugsCatalogo.has(slug));
ok(huerfanos.length === 0, `sin entradas ajenas al catálogo (${huerfanos.join(', ') || 'ninguna'})`);

console.log('\n== iconos-consistentes (slice 1): bundled == docs/juegos.json ==');
const rutaDocs = join(AQUI, '..', '..', 'docs', 'juegos.json');
const bytesBundled = readFileSync(rutaJson, 'utf8');
const bytesDocs = readFileSync(rutaDocs, 'utf8');
ok(bytesBundled === bytesDocs, 'taquilla/src/data/juegos.json es byte-idéntico a docs/juegos.json');

console.log('\n== iconos-consistentes (slice 1): 106/106 slugs de animalitos con icono ==');
const opcionesAnimal = catalogo.juegos.filter((j) => j.familia === 'animalitos').flatMap((j) => j.opciones);
ok(opcionesAnimal.length === 643, `643 opciones de animalitos en el catálogo (${opcionesAnimal.length})`);
const sinIcono = opcionesAnimal.filter((o) => typeof o.icono !== 'string' || o.icono === '');
ok(
  sinIcono.length === 0,
  `toda opción de animalitos con icono no vacío (${opcionesAnimal.length - sinIcono.length}/643)` +
    (sinIcono.length ? ` (sin icono: ${sinIcono.map((o) => o.value).join(', ')})` : ''),
);
const slugsDistintos = new Set(opcionesAnimal.map((o) => o.value));
ok(slugsDistintos.size === 106, `106 slugs distintos de animalitos (${slugsDistintos.size})`);
const inconsistencias = [];
for (const slug of slugsDistintos) {
  const iconos = new Set(opcionesAnimal.filter((o) => o.value === slug).map((o) => o.icono));
  if (iconos.size !== 1) inconsistencias.push(slug);
}
ok(inconsistencias.length === 0, `mismo slug → mismo icono en todos los juegos (${inconsistencias.join(', ') || 'ninguna'})`);
const opcionesNoAnimal = catalogo.juegos.filter((j) => j.familia !== 'animalitos').flatMap((j) => j.opciones);
ok(
  opcionesNoAnimal.every((o) => o.icono === undefined || o.icono === null),
  'familias no animal: sin icono (sanitizarOpcion descarta null/vacío)',
);

console.log('\n== Ticket impreso (formato multi-juego, 2026-09) ==');
ok(formatearJugada({ tipo: 'animalitos', animal: 'Perro', numero: '14' }) === 'Perro #14', 'animalitos: «Animal #N°»');
ok(formatearJugada({ tipo: 'terminales', numero: '05' }) === '#05', 'terminal: solo el número (sin duplicar)');
ok(formatearJugada({ tipo: 'tripletas', numero: '157', modalidad: 'Triple C', signo: 'Sagitario' }) === 'Triple C #157 Sagitario', 'tripleta zodiacal: modalidad + número + signo');
ok(formatearJugada({ tipo: 'tripletas', numero: '005', modalidad: 'Triple A' }) === 'Triple A #005', 'tripleta numérica: modalidad + número');
ok(formatearJugada({ tipo: 'animalitos', animal: null, numero: null }) === '-', 'sin datos → «-»');
ok(labelModalidad('triple_b') === 'Triple B' && labelModalidad('triple_c') === 'Triple C', 'labelModalidad: códigos conocidos');
ok(labelModalidad(null) === null && labelModalidad('otro') === null, 'labelModalidad: desconocidos → null');
const juegosTicket = nombresJuegos([{ game: 'Triple Zulia' }, { game: 'Lotto Activo' }, { game: 'Triple Zulia' }, { game: '  ' }]);
ok(juegosTicket.length === 2 && juegosTicket[0] === 'Triple Zulia' && juegosTicket[1] === 'Lotto Activo', 'nombresJuegos: únicos en orden de aparición');
ok(labelDeSigno(catalogo.porSlug.get('triple-zulia'), 'SAG') === 'Sagitario', 'labelDeSigno: SAG → Sagitario');
ok(labelDeSigno(catalogo.porSlug.get('triple-zulia'), 'sag') === 'Sagitario', 'labelDeSigno: case-insensitive');
ok(labelDeSigno(catalogo.porSlug.get('triple-zulia'), 'XXX') === null, 'labelDeSigno: sigla inexistente → null');
ok(labelDeSigno(lottoActivo, 'SAG') === null, 'labelDeSigno: familia no zodiacal → null');

console.log('\n== atajos-2026-09: anulación F10 (serial + último pendiente) ==');
ok(normalizarSerial('  tkt-000123 ') === 'TKT-000123', 'normalizarSerial: trim + mayúsculas');
ok(normalizarSerial('tkt-000123') === 'TKT-000123', 'normalizarSerial: mayúsculas sin espacios');
ok(normalizarSerial('') === '', 'normalizarSerial: vacío → vacío');
ok(serialCoincide('tkt-000123', 'TKT-000123'), 'serialCoincide: ignora mayúsculas');
ok(serialCoincide('  TKT-000123  ', 'TKT-000123'), 'serialCoincide: recorta extremos del tecleado');
ok(!serialCoincide('TKT-000124', 'TKT-000123'), 'serialCoincide: serial distinto → false (anti-tecleo)');
ok(!serialCoincide('TKT-000123', null), 'serialCoincide: ticket_code nulo → false');
ok(!serialCoincide('TKT-000123', undefined), 'serialCoincide: ticket_code ausente → false');
ok(!serialCoincide('', ''), 'serialCoincide: vacío vs vacío → false (sin código no hay match)');
const ticketsApi = [
  { id: 3, estado: 'anulada', ticket_code: 'T3', created_at: '2026-09-26T12:00:00Z' },
  { id: 2, estado: 'pendiente', ticket_code: 'T2', created_at: '2026-09-26T11:00:00Z' },
  { id: 1, estado: 'pendiente', ticket_code: 'T1', created_at: '2026-09-26T10:00:00Z' },
  { id: 4, estado: 'pendiente', ticket_code: 'T4', created_at: '2026-09-26T09:00:00Z' },
];
ok(ultimoTicketPendiente(ticketsApi)?.ticket_code === 'T2', 'ultimoTicketPendiente: pendiente más reciente (T2)');
ok(ultimoTicketPendiente(ticketsApi.filter((t) => t.estado !== 'pendiente')) === null, 'sin pendientes → null');
ok(ultimoTicketPendiente([]) === null, 'lista vacía → null');
ok(
  ultimoTicketPendiente([
    { id: 10, estado: 'pendiente', created_at: '2026-09-26T10:00:00Z' },
    { id: 12, estado: 'pendiente', created_at: '2026-09-26T10:00:00Z' },
  ]).id === 12,
  'empate de created_at → desempate por id descendente',
);
ok(
  ultimoTicketPendiente([
    { id: 7, estado: 'pendiente' },
    { id: 9, estado: 'pendiente' },
  ]).id === 9,
  'created_at ausente → id descendente',
);
ok(ultimoTicketPendiente([{ id: 5, estado: 'pendiente', created_at: 'fecha-inválida' }]).id === 5, 'created_at inválido → cae al id');
ok(ticketsApi.length === 4 && ticketsApi[0].id === 3, 'ultimoTicketPendiente no muta la lista original');

console.log('\n== S1 pagos: monedaDeApuesta (D4: Bs→bs, $→usd, ambas→mixto) ==');
ok(monedaDeApuesta({ amount_bs: 10, amount_usd: 0 }) === 'bs', 'solo Bs → bs');
ok(monedaDeApuesta({ amount_bs: 0, amount_usd: 5 }) === 'usd', 'solo $ → usd');
ok(monedaDeApuesta({ amount_bs: 10, amount_usd: 5 }) === 'mixto', 'Bs + $ → mixto');
ok(monedaDeApuesta({ amount_bs: 0, amount_usd: 0 }) === 'bs', 'sin montos → bs (moneda base)');
ok(monedaDeApuesta({}) === 'bs', 'apuesta sin montos → bs');
ok(monedaDeApuesta(null) === 'bs', 'apuesta nula → bs (defensivo)');

console.log('\n== S1 pagos: esPagableApuesta (ganadora o pendiente con resultado) ==');
ok(esPagableApuesta({ estado: 'ganadora', resultado_id: 7 }) === true, 'ganadora con resultado → pagable');
ok(esPagableApuesta({ estado: 'ganadora' }) === true, 'ganadora → pagable (P0: el backend la exige)');
ok(esPagableApuesta({ estado: 'pendiente', resultado_id: 7 }) === true, 'pendiente con resultado_id → pagable (legacy)');
ok(esPagableApuesta({ estado: 'pendiente' }) === false, 'pendiente sin resultado → NO pagable');
ok(esPagableApuesta({ estado: 'perdida' }) === false, 'perdida → NO pagable');
ok(esPagableApuesta({ estado: 'pagada' }) === false, 'pagada → NO pagable');
ok(esPagableApuesta({ estado: 'vencido' }) === false, 'vencido → NO pagable');
ok(esPagableApuesta(null) === false, 'apuesta nula → NO pagable');

console.log('\n== S1 pagos: payloadPago exacto (D4: {apuesta_id, tipo:egreso, moneda}, SIN montos) ==');
const payloadBs = payloadPago({ id: 123, amount_bs: 10, amount_usd: 0 });
ok(
  JSON.stringify(payloadBs) === JSON.stringify({ apuesta_id: 123, tipo: 'egreso', moneda: 'bs' }),
  `payload exacto Bs (${JSON.stringify(payloadBs)})`,
);
ok(!('amount_bs' in payloadBs) && !('amount_usd' in payloadBs), 'payload sin montos (el backend aplica el premio del motor)');
ok(
  JSON.stringify(payloadPago({ id: 456, amount_bs: 0, amount_usd: 5 })) ===
    JSON.stringify({ apuesta_id: 456, tipo: 'egreso', moneda: 'usd' }),
  'payload exacto USD',
);
ok(
  JSON.stringify(payloadPago({ id: 789, amount_bs: 10, amount_usd: 5 })) ===
    JSON.stringify({ apuesta_id: 789, tipo: 'egreso', moneda: 'mixto' }),
  'payload exacto mixto',
);

console.log('\n== S1 pagos: esPagableTicket (P0: ticket ganador con ganadora sin pagar) ==');
ok(
  esPagableTicket({ estado: 'ganador', apuestas: [{ estado: 'ganadora', resultado_id: 7 }, { estado: 'pagada' }] }) === true,
  'ticket ganador con apuesta ganadora sin pagar → pagable (P0)',
);
ok(
  esPagableTicket({ estado: 'pendiente', apuestas: [{ estado: 'ganadora', resultado_id: 7 }] }) === true,
  'ticket pendiente con ganadora → pagable',
);
ok(
  esPagableTicket({ estado: 'pendiente', apuestas: [{ estado: 'pendiente', resultado_id: 7 }] }) === true,
  'ticket pendiente con apuesta pendiente+resultado → pagable (legacy)',
);
ok(esPagableTicket({ estado: 'pagada', apuestas: [{ estado: 'pagada' }] }) === false, 'ticket pagada → NO pagable');
ok(
  esPagableTicket({ estado: 'pendiente', apuestas: [{ estado: 'perdida' }] }) === false,
  'ticket pendiente sin apuestas pagables → NO pagable',
);
ok(
  esPagableTicket({ estado: 'ganador', apuestas: [{ estado: 'pagada' }] }) === false,
  'ticket ganador con todas pagadas → NO pagable',
);
ok(esPagableTicket({ estado: 'ganador', apuestas: [] }) === false, 'ticket sin apuestas → NO pagable');
ok(esPagableTicket({ estado: 'ganador' }) === false, 'ticket sin clave apuestas → NO pagable');
ok(esPagableTicket(null) === false, 'ticket nulo → NO pagable');

console.log('\n== S1 pagos: acumularPremio (suma response.premio) ==');
const premioTotal = acumularPremio([
  { premio: { premio_bs: 1500, premio_usd: 0 } },
  { premio: { premio_bs: 0, premio_usd: 5 } },
  { premio: { premio_bs: 500, premio_usd: 2.5 } },
]);
ok(premioTotal.premio_bs === 2000 && premioTotal.premio_usd === 7.5, `suma Bs y $ entre respuestas (${JSON.stringify(premioTotal)})`);
ok(acumularPremio([]).premio_bs === 0 && acumularPremio([]).premio_usd === 0, 'sin respuestas → 0/0');
ok(
  acumularPremio([null, { premio: null }, { premio: { premio_bs: 10, premio_usd: 0 } }]).premio_bs === 10,
  'respuestas sin premio se ignoran y no rompen la suma',
);

console.log('\n== S3 TQ-02/TQ-03: estadoTicket — tabla de verdad (design §7) ==');
// Chips `ganador`/`vencido`; "resuelto sin ganadores" SOLO si no hay apuestas
// pendiente/ganadora Y tiene_ganadores=false; nunca inventa (cae al estado real).
ok(
  estadoTicket({ estado: 'ganador', tiene_ganadores: true, apuestas: [{ estado: 'ganadora' }] }) === 'ganador',
  'ticket ganador con ganadora sin pagar → chip ganador (abierto, P0)',
);
ok(
  estadoTicket({ estado: 'vencido', tiene_ganadores: true, apuestas: [{ estado: 'vencido' }] }) === 'vencido',
  'ticket vencido con premio no cobrado (tiene_ganadores=true) → chip vencido, NO resuelto',
);
ok(
  estadoTicket({ estado: 'pendiente', tiene_ganadores: false, apuestas: [{ estado: 'perdida' }, { estado: 'vencido' }] }) === 'resuelto-sin-ganadores',
  'apuestas perdida/vencido + tiene_ganadores=false → resuelto sin ganadores',
);
ok(
  estadoTicket({ estado: 'pendiente', tiene_ganadores: false, apuestas: [{ estado: 'pendiente' }] }) === 'pendiente',
  'apuesta pendiente real → NO se deriva resuelto (estado real)',
);
ok(
  estadoTicket({ estado: 'pendiente', tiene_ganadores: false, apuestas: [{ estado: 'ganadora' }] }) === 'pendiente',
  'apuesta ganadora sin pagar → NO se deriva resuelto (ticket abierto)',
);
ok(
  estadoTicket({ estado: 'pendiente' }) === 'pendiente',
  'sin clave apuestas → cae al estado real (nunca inventa)',
);
ok(
  estadoTicket({ estado: 'pagada', apuestas: [{ estado: 'pagada' }] }) === 'pagada',
  'sin tiene_ganadores → cae al estado real (nunca inventa)',
);
ok(
  estadoTicket({ estado: 'anulada', apuestas: [] }) === 'anulada',
  'apuestas vacías → cae al estado real (nunca inventa)',
);
ok(estadoTicket(null) === '', 'ticket nulo → sin estado');

console.log('\n== S4 TQ-04: badgesResultado — figuras (trophy + animal + #numero + emoji catálogo) ==');
const monjeOpciones = catalogo.porSlug.get('monje-millonario').opciones;
const bFiguras = badgesResultado(
  { figuras: [{ animal: 'Delfín', numero: 0 }, { nombre_animal: 'Perro', numero: 27 }] },
  monjeOpciones,
);
ok(bFiguras.length === 2, `2 figuras → 2 badges (${bFiguras.length})`);
ok(bFiguras[0].icono === 'trophy' && bFiguras[0].texto === 'Delfín #0', `figura 1: trophy + 'Delfín #0' (${bFiguras[0].texto})`);
ok(bFiguras[0].emoji === '🐬', `figura 1: emoji del catálogo para Delfín (${bFiguras[0].emoji})`);
ok(bFiguras[1].icono === 'trophy' && bFiguras[1].texto === 'Perro #27' && bFiguras[1].emoji === '🐶', `figura 2: nombre_animal 'Perro #27' + emoji 🐶 (${bFiguras[1].texto})`);
const bFigSinEmoji = badgesResultado({ figuras: [{ animal: 'Unicornio', numero: 88 }] }, monjeOpciones);
ok(bFigSinEmoji.length === 1 && bFigSinEmoji[0].texto === 'Unicornio #88' && !('emoji' in bFigSinEmoji), 'figura cuyo label NO matchea opciones → sin emoji');
const bFigSinOpciones = badgesResultado({ figuras: [{ animal: 'Delfín', numero: 0 }] });
ok(bFigSinOpciones.length === 1 && !('emoji' in bFigSinOpciones[0]), 'sin opciones pasadas → sin emoji (defensivo)');
ok(badgesResultado({ figuras: [{ numero: 7 }] }).length === 0, 'figura sin animal/nombre_animal → se omite');

console.log('\n== S4 TQ-04: badgesResultado — comodín (gem: true→MEGA, A/B + nombre) ==');
const bComodin = badgesResultado({ comodin: true });
ok(bComodin.length === 1 && bComodin[0].icono === 'gem' && bComodin[0].texto === 'MEGA', `comodin true → gem 'MEGA' (${bComodin[0].texto})`);
const bComodinA = badgesResultado({ comodin: 'A', comodin_nombre: 'Leoncito' });
ok(bComodinA.length === 1 && bComodinA[0].icono === 'gem' && bComodinA[0].texto === 'COMODÍN A · Leoncito', `comodin A + nombre → 'COMODÍN A · Leoncito' (${bComodinA[0].texto})`);
const bComodinB = badgesResultado({ comodin: 'B', comodin_nombre: 'Selva Plus' });
ok(bComodinB[0].texto === 'COMODÍN B · Selva Plus', `comodin B + nombre → 'COMODÍN B · Selva Plus' (${bComodinB[0].texto})`);
ok(badgesResultado({ comodin: false }).length === 0, 'comodin false → sin badge');
ok(badgesResultado({ comodin: 'X' }).length === 0, 'comodin valor desconocido → sin badge');

console.log('\n== S4 TQ-04: badgesResultado — patronus/arrimao/pegadito ==');
const bPatronus = badgesResultado({ patronus: true });
ok(bPatronus.length === 1 && bPatronus[0].icono === 'crown' && bPatronus[0].texto === 'PATRONUS', `patronus truthy → crown 'PATRONUS' (${bPatronus[0].texto})`);
ok(badgesResultado({ patronus: false }).length === 0, 'patronus false → sin badge');
const bArrimao = badgesResultado({ arrimao: '1825' });
ok(bArrimao.length === 1 && bArrimao[0].icono === 'target' && bArrimao[0].texto === '#1825', `arrimao → target '#1825' (${bArrimao[0].texto})`);
const bPegadito = badgesResultado({ pegadito: '12345' });
ok(bPegadito.length === 1 && bPegadito[0].icono === 'hash' && bPegadito[0].texto === '#12345', `pegadito → hash '#12345' (${bPegadito[0].texto})`);

console.log('\n== S4 TQ-04: badgesResultado — combinación, orden y vacíos ==');
const bTodo = badgesResultado(
  {
    figuras: [{ animal: 'Delfín', numero: 0 }],
    comodin: true,
    patronus: true,
    arrimao: '1825',
    pegadito: '12345',
  },
  monjeOpciones,
);
ok(bTodo.length === 5, `todas las claves → 5 badges (${bTodo.length})`);
ok(JSON.stringify(bTodo.map((b) => b.icono)) === JSON.stringify(['trophy', 'gem', 'crown', 'target', 'hash']), `orden: trophy→gem→crown→target→hash (${bTodo.map((b) => b.icono).join(',')})`);
ok(badgesResultado({}).length === 0, 'resultado vacío → []');
ok(badgesResultado(null).length === 0, 'resultado null → []');
ok(badgesResultado({ numero: 5, nombre_animal: 'Perro' }).length === 0, 'claves legadas sin claves nuevas → [] (no inventa)');

console.log('\n== S5 TQ-05a: modalidadesDisponibles desde premios.modalidades (D2) ==');
// D2 (design §4): las opciones de modalidad salen del catálogo
// (premios.modalidades), nunca hardcodeadas; DEFS aporta label/cifras/signo.
const mTrio = modalidadesDisponibles(catalogo.porSlug.get('trio-activo'));
ok(
  JSON.stringify(mTrio.map((m) => m.clave)) === JSON.stringify(['triple_a', 'punta', 'terminal']),
  `trio-activo: [triple_a(base), punta, terminal] (${mTrio.map((m) => m.clave).join(',')})`,
);
ok(mTrio[0].clave === 'triple_a' && mTrio[0].label === 'Triple' && mTrio[0].multiplicador === 600, 'trio-activo: base sintetizada triple_a label "Triple" 600× (premios.base)');
ok(mTrio[1].multiplicador === 60 && mTrio[2].multiplicador === 60, 'trio-activo: multiplicadores 60× del catálogo');
ok(mTrio[1].label === 'Punta' && mTrio[2].label === 'Terminal', 'trio-activo: labels "Punta"/"Terminal"');
const mChance = modalidadesDisponibles(catalogo.porSlug.get('triple-chance'));
ok(
  JSON.stringify(mChance.map((m) => m.clave)) === JSON.stringify(['triple_a', 'triple_b', 'punta', 'cruzado', 'terminal', 'signo_solo', 'triple_a_b', 'signo_triple', 'signo_terminal']),
  `chance: base + single-draw + multi-selección (S6) sin tiers (${mChance.map((m) => m.clave).join(',')})`,
);
ok(!mChance.some((m) => ['cruzado_10', 'solo_a_b'].includes(m.clave)), 'chance: tiers cruzado_10/solo_a_b NO se ofrecen (derivados)');
ok(mChance.find((m) => m.clave === 'signo_solo').digitos === 0 && mChance.find((m) => m.clave === 'signo_solo').requiereSigno === true, 'signo_solo: 0 cifras + signo obligatorio');
const mZam = modalidadesDisponibles(catalogo.porSlug.get('triple-zamorano'));
ok(mZam.some((m) => m.clave === 'uña' && m.label === 'Una' && m.digitos === 1 && m.multiplicador === 5), 'zamorano: uña 1 cifra 5×');
ok(mZam.some((m) => m.clave === 'signo_uña' && m.digitos === 1 && m.requiereSigno), 'zamorano: signo_uña 1 cifra + signo');
const mArr = modalidadesDisponibles(catalogo.porSlug.get('el-arrejuntado'));
ok(mArr.find((m) => m.clave === 'arrimao').digitos === 4 && mArr.find((m) => m.clave === 'pegadito').digitos === 5, 'arrejuntado: arrimao 4 cifras, pegadito 5');
ok(mArr.some((m) => m.clave === 'triple_a' && m.tipo === 'triple_a' && m.label === 'Triple A'), 'arrejuntado: triple_a desde premios.modalidades (600×)');
ok(modalidadesDisponibles(catalogo.porSlug.get('lotto-activo')).length === 0, 'lotto-activo: sin modalidades → []');
const mZul = modalidadesDisponibles(catalogo.porSlug.get('triple-zulia'));
ok(mZul.find((m) => m.clave === 'signo_triple').tipo === 'triple_c' && mZul.find((m) => m.clave === 'signo_triple').label === 'Triple C', 'zulia: signo_triple → payload tipo triple_c, label "Triple C"');
ok('punta' in DEFS && 'arrimao' in DEFS && 'signo_solo' in DEFS && 'uña' in DEFS, 'DEFS cubre las claves single-draw (punta/arrimao/signo_solo/uña)');

console.log('\n== S5 corrective (gate): base sintetizada por familia (design §4 "— (base)") ==');
// Gate finding: los juegos con modalidades separadas perdieron el producto
// BASE — el selector nuevo solo listaba claves de premios.modalidades. La
// familia sintetiza la base ANTES de las claves del catálogo, deduplicando
// por clave (el-arrejuntado ya lista triple_a/triple_b → no se duplican).
const mZulBase = modalidadesDisponibles(catalogo.porSlug.get('triple-zulia'));
ok(
  JSON.stringify(mZulBase.map((m) => m.clave)) === JSON.stringify(['triple_a', 'triple_b', 'terminal', 'signo_triple', 'signo_terminal']),
  `zulia: base triple_a/triple_b ANTES de las claves del catálogo (${mZulBase.map((m) => m.clave).join(',')})`,
);
ok(mZulBase[0].label === 'Triple A' && mZulBase[1].label === 'Triple B', 'zulia: labels base "Triple A"/"Triple B"');
ok(
  mZulBase[0].digitos === 3 && mZulBase[0].requiereSigno === false &&
    mZulBase[1].digitos === 3 && mZulBase[1].requiereSigno === false,
  'zulia: base 3 cifras, sin signo',
);
ok(mZulBase[0].multiplicador === 600 && mZulBase[1].multiplicador === 600, 'zulia: base 600× (premios.base del catálogo, no hardcode)');
ok(
  JSON.stringify(mZulBase.map((m) => m.clave)) === JSON.stringify(modalidadesDisponibles(catalogo.porSlug.get('triple-caliente')).map((m) => m.clave)),
  'caliente: misma base sintetizada que zulia (misma familia)',
);
ok(
  JSON.stringify(mArr.map((m) => m.clave)) === JSON.stringify(['arrimao', 'pegadito', 'triple_a', 'triple_b', 'signo_triple']),
  `arrejuntado: SIN duplicar triple_a/triple_b (catálogo ya las lista) (${mArr.map((m) => m.clave).join(',')})`,
);
ok(
  mArr.filter((m) => m.clave === 'triple_a').length === 1 && mArr.filter((m) => m.clave === 'triple_b').length === 1,
  'arrejuntado: exactamente 1 entrada por clave base (dedupe por clave)',
);
ok(
  JSON.stringify(modalidadesDisponibles(catalogo.porSlug.get('triple-facil')).map((m) => m.clave)) === JSON.stringify(['triple_a', 'terminal', 'aproximacion']),
  'triple-facil (numérica): base triple_a + terminal + aproximacion',
);
ok(
  JSON.stringify(modalidadesDisponibles(catalogo.porSlug.get('triple-tachira')).map((m) => m.clave)) === JSON.stringify(['triple_a', 'triple_b', 'terminal', 'signo_triple']),
  'tachira (zodiacal): base triple_a/triple_b + terminal + signo_triple',
);
ok(
  JSON.stringify(modalidadesDisponibles(catalogo.porSlug.get('triple-zamorano')).map((m) => m.clave)) ===
    JSON.stringify(['triple_a', 'triple_b', 'uña', 'terminal', 'signo_uña', 'signo_triple', 'signo_terminal']),
  `zamorano: base triple_a/triple_b + catálogo (${modalidadesDisponibles(catalogo.porSlug.get('triple-zamorano')).map((m) => m.clave).join(',')})`,
);

console.log('\n== S5: validarDigitos (^\\d{1,N}$ → padStart, design §4) ==');
ok(validarDigitos('45', 2) === '45', '"45" (2 cifras) → "45"');
ok(validarDigitos('5', 2) === '05', '"5" → "05" (padding a 2)');
ok(validarDigitos('1825', 4) === '1825', '"1825" (4 cifras) → "1825"');
ok(validarDigitos('10503', 5) === '10503', '"10503" (5 cifras) → "10503"');
ok(validarDigitos('453', 2) === null, '"453" excede 2 cifras → null (sin POST)');
ok(validarDigitos('abc', 2) === null, 'no numérico → null');
ok(validarDigitos('', 2) === null, 'vacío → null');
ok(validarDigitos(' 05 ', 2) === '05', 'con espacios alrededor se recorta');

console.log('\n== S5: construirCombinacion single-draw (payload exacto contrato §2.1) ==');
const comboDe = (slug, clave, entrada) =>
  construirCombinacion(modalidadesDisponibles(catalogo.porSlug.get(slug)).find((m) => m.clave === clave), entrada);
ok(JSON.stringify(comboDe('trio-activo', 'punta', { numero: '5' })) === JSON.stringify({ tipo: 'punta', numero: '05' }), 'punta "5" → {tipo:punta, numero:05}');
ok(JSON.stringify(comboDe('trio-activo', 'terminal', { numero: '52' })) === JSON.stringify({ tipo: 'terminal', numero: '52' }), 'terminal "52" → payload exacto');
ok(JSON.stringify(comboDe('triple-zamorano', 'uña', { numero: '2' })) === JSON.stringify({ tipo: 'uña', numero: '2' }), 'uña "2" → payload exacto');
ok(JSON.stringify(comboDe('triple-facil', 'aproximacion', { numero: '51' })) === JSON.stringify({ tipo: 'aproximacion', numero: '51' }), 'aproximacion "51" → payload exacto');
ok(JSON.stringify(comboDe('triple-zulia', 'signo_terminal', { numero: '59', signo: 'LEO' })) === JSON.stringify({ tipo: 'signo_terminal', numero: '59', signo: 'LEO' }), 'signo_terminal → {tipo, numero, signo}');
ok(JSON.stringify(comboDe('triple-chance', 'signo_solo', { signo: 'LEO' })) === JSON.stringify({ tipo: 'signo_solo', signo: 'LEO' }), 'signo_solo → solo signo (sin numero)');
ok(JSON.stringify(comboDe('el-arrejuntado', 'arrimao', { numero: '1825' })) === JSON.stringify({ tipo: 'arrimao', numero: '1825' }), 'arrimao 4 cifras → payload exacto');
ok(JSON.stringify(comboDe('el-arrejuntado', 'pegadito', { numero: '10503' })) === JSON.stringify({ tipo: 'pegadito', numero: '10503' }), 'pegadito 5 cifras → payload exacto');
ok(JSON.stringify(comboDe('triple-zulia', 'signo_triple', { numero: '259', signo: 'LEO' })) === JSON.stringify({ tipo: 'triple_c', numero: '259', signo: 'LEO' }), 'signo_triple → tipo triple_c (Triple C)');
ok(comboDe('trio-activo', 'punta', { numero: '453' }) === null, 'dígitos inválidos → null (no se POSTea)');
ok(comboDe('triple-zulia', 'signo_terminal', { numero: '59' }) === null, 'signo faltante → null');
ok(comboDe('triple-chance', 'signo_solo', {}) === null, 'signo_solo sin signo → null');

console.log('\n== S5 corrective (gate): payload de la base sintetizada (design §4 "— (base)") ==');
// La base triple viaja como {tipo:'triple_a'|'triple_b', numero} con padding a
// 3 cifras; la numérica mantiene la semántica legacy "05"→"005"; el signo solo
// se exige cuando la modalidad lo requiere (base: nunca).
ok(
  JSON.stringify(comboDe('triple-zulia', 'triple_a', { numero: '005' })) === JSON.stringify({ tipo: 'triple_a', numero: '005' }),
  'zulia base triple_a "005" → {tipo:triple_a, numero:005}',
);
ok(
  JSON.stringify(comboDe('triple-zulia', 'triple_b', { numero: '157' })) === JSON.stringify({ tipo: 'triple_b', numero: '157' }),
  'zulia base triple_b "157" → {tipo:triple_b, numero:157}',
);
ok(
  JSON.stringify(comboDe('trio-activo', 'triple_a', { numero: '05' })) === JSON.stringify({ tipo: 'triple_a', numero: '005' }),
  'trio-activo base triple_a "05" → "005" (semántica legacy FIX D)',
);
ok(
  JSON.stringify(comboDe('triple-chance', 'triple_a', { numero: '5' })) === JSON.stringify({ tipo: 'triple_a', numero: '005' }),
  'chance base triple_a "5" → "005" (padStart 3)',
);
ok(
  comboDe('triple-zulia', 'triple_a', { numero: '45' }) !== null && comboDe('triple-zulia', 'triple_a', { numero: '45' }).signo === undefined,
  'zulia base triple_a: sin signo en el payload (requiereSigno false)',
);
ok(
  JSON.stringify(comboDe('el-arrejuntado', 'triple_a', { numero: '452' })) === JSON.stringify({ tipo: 'triple_a', numero: '452' }),
  'arrejuntado triple_a (catálogo, no sintetizado) → payload idéntico',
);
ok(
  comboDe('trio-activo', 'triple_a', { numero: '4531' }) === null,
  'trio-activo base triple_a "4531" (4 cifras) → null (tope 3)',
);
ok(
  JSON.stringify(comboDe('triple-facil', 'triple_a', { numero: '07' })) === JSON.stringify({ tipo: 'triple_a', numero: '007' }),
  'triple-facil base triple_a "07" → "007" (numérica, legacy)',
);

console.log('\n== S5: grafo de zonas con ctx single-draw (D3 — A2 intactos sin ctx) ==');
ok(
  JSON.stringify(buildZoneGraph('numerica', {}).zonas) === JSON.stringify(baseEsperada),
  'numérica sin ctx: base idéntica a hoy (A2)',
);
ok(
  JSON.stringify(buildZoneGraph('zodiacal', {}).zonas) === JSON.stringify(['juegos', 'modalidad', 'seleccion', 'horarios', 'numero', 'monto', 'anadir', 'resumen']),
  'zodiacal sin ctx: idéntico a hoy (A2)',
);
ok(
  JSON.stringify(buildZoneGraph('zodiacal', { triple_c: true }).zonas) === JSON.stringify(['juegos', 'modalidad', 'seleccion', 'signo', 'horarios', 'numero', 'monto', 'anadir', 'resumen']),
  'zodiacal triple_c: idéntico a hoy (A2)',
);
const gPunta = buildZoneGraph('numerica', { modalidad: true });
ok(
  JSON.stringify(gPunta.zonas) === JSON.stringify(['juegos', 'modalidad', 'seleccion', 'horarios', 'numero', 'monto', 'anadir', 'resumen']),
  `numérica con modalidad: juegos → modalidad → seleccion (${gPunta.zonas.join('→')})`,
);
const gSignoSolo = buildZoneGraph('zodiacal', { modalidad: true, signo: true, numero: false });
ok(!gSignoSolo.incluye('numero') && gSignoSolo.incluye('signo') && gSignoSolo.incluye('modalidad'), 'signo_solo: sin numero, con signo y modalidad');
ok(
  JSON.stringify(gSignoSolo.zonas) === JSON.stringify(['juegos', 'modalidad', 'seleccion', 'signo', 'horarios', 'monto', 'anadir', 'resumen']),
  `signo_solo: ${gSignoSolo.zonas.join('→')}`,
);
ok(
  JSON.stringify(buildZoneGraph('zodiacal', { signo: true }).zonas) === JSON.stringify(buildZoneGraph('zodiacal', { triple_c: true }).zonas),
  'alias: ctx.signo ≡ ctx.triple_c (legacy intacto, D3)',
);
const gSeg = buildZoneGraph('numerica', { segundaSeleccion: true });
ok(
  JSON.stringify(gSeg.zonas) === JSON.stringify(['juegos', 'seleccion', 'horarios', 'numero', 'numero_b', 'monto', 'anadir', 'resumen']),
  `segundaSeleccion: numero_b entre numero y monto (${gSeg.zonas.join('→')})`,
);
ok(gSeg.incluye('numero_b'), 'numero_b incluida con ctx.segundaSeleccion (S6-ready)');
ok(
  zonaPendienteSeleccion({ familia: 'zodiacal', tripleModalidad: null, signoElegido: false, animalElegido: false, signo: true }) === 'signo',
  'modalidad nueva con signo obligatorio sin elegir → pendiente signo',
);
ok(
  zonaPendienteSeleccion({ familia: 'zodiacal', tripleModalidad: null, signoElegido: true, animalElegido: false, signo: true }) === null,
  'con signo elegido → sin pendiente',
);

console.log('\n== S6 TQ-05b: multi-selección same-draw (design §4, contrato §2.2) ==');
// DEFS ahora cubre las claves multi-selección: se OFRECEN desde el catálogo
// (cruzado/triple_a_b en Chance; tripleta en Cazalotón/Chaima). Los tiers
// derivados (cruzado_10, solo_a_b) NO entran a DEFS: siguen excluidos.
const mChanceS6 = modalidadesDisponibles(catalogo.porSlug.get('triple-chance'));
ok(mChanceS6.some((m) => m.clave === 'cruzado'), 'chance: cruzado SE ofrece en S6');
ok(mChanceS6.some((m) => m.clave === 'triple_a_b'), 'chance: triple_a_b SE ofrece en S6');
ok(!mChanceS6.some((m) => ['cruzado_10', 'solo_a_b'].includes(m.clave)), 'chance: tiers cruzado_10/solo_a_b siguen excluidos (derivados)');
const mCruzado = mChanceS6.find((m) => m.clave === 'cruzado');
ok(mCruzado.multiplicador === 3000 && mCruzado.digitos === 2, 'cruzado: 3000× desde el catálogo, 2 cifras por punta');
const mPar = mChanceS6.find((m) => m.clave === 'triple_a_b');
ok(mPar.multiplicador === 200000 && mPar.digitos === 3, 'triple_a_b: 200000× desde el catálogo, 3 cifras por triple');
const mCaza = modalidadesDisponibles(catalogo.porSlug.get('cazaloton'));
ok(JSON.stringify(mCaza.map((m) => m.clave)) === JSON.stringify(['tripleta']), `cazaloton: solo tripleta (${mCaza.map((m) => m.clave).join(',')})`);
ok(mCaza[0].multiplicador === 200, 'cazaloton: tripleta 200× desde el catálogo');
ok(modalidadesDisponibles(catalogo.porSlug.get('loto-chaima')).find((m) => m.clave === 'tripleta').multiplicador === 50, 'loto-chaima: tripleta 50× desde el catálogo');

// Payloads exactos multi-selección (contrato §2.2)
ok(
  JSON.stringify(construirCombinacionMulti(mCruzado, { numero: '75', numeroB: '14' })) ===
    JSON.stringify({ modalidad: 'cruzado', selecciones: [{ tipo: 'punta', numero: '75' }, { tipo: 'punta', numero: '14' }] }),
  'cruzado "75/14" → {modalidad:cruzado, selecciones:[{punta,75},{punta,14}]}',
);
ok(
  JSON.stringify(construirCombinacionMulti(mPar, { numero: '756', numeroB: '146' })) ===
    JSON.stringify({ modalidad: 'triple_a_b', selecciones: [{ tipo: 'triple_a', numero: '756' }, { tipo: 'triple_b', numero: '146' }] }),
  'triple_a_b "756/146" → payload exacto',
);
ok(
  JSON.stringify(construirCombinacionMulti(mCruzado, { numero: '7', numeroB: '5' })) ===
    JSON.stringify({ modalidad: 'cruzado', selecciones: [{ tipo: 'punta', numero: '07' }, { tipo: 'punta', numero: '05' }] }),
  'cruzado sin padding "7/5" → "07"/"05" (el motor normaliza)',
);
ok(construirCombinacionMulti(mCruzado, { numero: '753', numeroB: '14' }) === null, 'cruzado con punta de 3 cifras → null (sin POST)');
ok(construirCombinacionMulti(mPar, { numero: '75', numeroB: '146' }) === null, 'triple_a_b con triple de 2 cifras → null');
ok(construirCombinacionMulti(null, { numero: '75', numeroB: '14' }) === null, 'sin modalidad multi → null');

ok(
  JSON.stringify(construirTripleta(['Perro', 'Gato', 'León'])) ===
    JSON.stringify({ modalidad: 'tripleta', selecciones: [{ animal: 'Perro' }, { animal: 'Gato' }, { animal: 'León' }] }),
  'tripleta 3 animales → {modalidad:tripleta, selecciones:[{animal}×3]}',
);
ok(construirTripleta(['Perro', 'Gato']) === null, 'tripleta con 2 animales → null');
ok(construirTripleta([]) === null, 'tripleta sin animales → null');

// Contador tripleta (design §5: tope 3, toggle Space/Enter, contador "n/3")
ok(JSON.stringify(alternarSeleccionAnimal([], 'Perro')) === JSON.stringify(['Perro']), 'toggle agrega el primer animal');
ok(JSON.stringify(alternarSeleccionAnimal(['Perro'], 'Perro')) === JSON.stringify([]), 'toggle quita el animal repetido');
ok(
  JSON.stringify(alternarSeleccionAnimal(['Perro', 'Gato', 'León'], 'Toro')) === JSON.stringify(['Perro', 'Gato', 'León']),
  'tope 3: el 4º animal NO se añade',
);
ok(
  JSON.stringify(alternarSeleccionAnimal(['Perro', 'Gato'], 'León')) === JSON.stringify(['Perro', 'Gato', 'León']),
  'con 2 añade el 3º conservando el orden de selección',
);

// Grafos multi-selección (design §5)
const gMulti = buildZoneGraph('zodiacal', { modalidad: true, segundaSeleccion: true });
ok(
  JSON.stringify(gMulti.zonas) === JSON.stringify(['juegos', 'modalidad', 'seleccion', 'horarios', 'numero', 'numero_b', 'monto', 'anadir', 'resumen']),
  `multi (cruzado/par): numero_b entre numero y monto (${gMulti.zonas.join('→')})`,
);
const gTripleta = buildZoneGraph('animalitos', {});
ok(!gTripleta.incluye('numero') && gTripleta.incluye('seleccion'), 'tripleta (animalitos): sin numero, seleccion presente (A2 intacto)');
ok(
  zonaPendienteSeleccion({ familia: 'animalitos', tripleModalidad: null, signoElegido: false, animalElegido: true, modalidadAnimalitos: 'tripleta', seleccionesAnimales: 2 }) === 'seleccion',
  'tripleta con 2/3 animales → pendiente seleccion',
);
ok(
  zonaPendienteSeleccion({ familia: 'animalitos', tripleModalidad: null, signoElegido: false, animalElegido: false, modalidadAnimalitos: 'tripleta', seleccionesAnimales: 3 }) === null,
  'tripleta con 3/3 → sin pendiente',
);
ok(
  zonaPendienteSeleccion({ familia: 'animalitos', tripleModalidad: null, signoElegido: false, animalElegido: true }) === null,
  'animalitos base con animal → sin pendiente (compat)',
);

console.log('\n== S1 comprobante: buildReciboLines (spec taquilla-comprobante-pago) ==');
const ticketRecibo = {
  ticket_code: 'TKT-999',
  apuestas: [
    {
      id: 1,
      estado: 'ganadora',
      amount_bs: 10,
      amount_usd: 0,
      juego: { name: 'Lotto Activo' },
      combinacion: JSON.stringify({ animal: 'Perro', numero: 14 }),
    },
    {
      id: 2,
      estado: 'perdida',
      amount_bs: 5,
      amount_usd: 0,
      juego: { name: 'Lotto Activo' },
      combinacion: { animal: 'Gato', numero: 3 },
    },
    {
      id: 3,
      estado: 'pendiente',
      amount_bs: 0,
      amount_usd: 2,
      juego: { name: 'Triple Zulia' },
      combinacion: JSON.stringify({ tipo: 'triple_c', numero: '157', signo: 'SAG' }),
    },
  ],
};
// Lote: solo la jugada 1 se pagó en este batch → PAGADA + premio; el resto
// conserva su estado real y premio 0 (no pagadas en el lote).
const recibo = buildReciboLines(ticketRecibo, [
  { premio: { premio_bs: 300, premio_usd: 0 } },
  null,
  null,
]);
ok(recibo.lines.length === 3, `1 línea por apuesta (${recibo.lines.length})`);
ok(recibo.lines[0].estado === 'PAGADA', 'jugada pagada en el lote → PAGADA');
ok(recibo.lines[1].estado === 'PERDIDA', 'apuesta perdida no pagada → PERDIDA');
ok(recibo.lines[2].estado === 'PENDIENTE', 'apuesta pendiente no pagada → PENDIENTE');
ok(recibo.lines[0].premioBs === 300 && recibo.lines[0].premioUsd === 0, 'premio por jugada desde la respuesta de pago');
ok(recibo.lines[1].premioBs === 0 && recibo.lines[2].premioBs === 0, 'jugadas no pagadas → premio 0');
ok(recibo.lines[0].jugada === 'Perro #14', `jugada animalitos: «Perro #14» (${recibo.lines[0].jugada})`);
ok(recibo.lines[2].jugada === 'Triple C #157 SAG', `jugada tripleta zodiacal (${recibo.lines[2].jugada})`);
ok(recibo.lines[0].game === 'Lotto Activo' && recibo.lines[2].game === 'Triple Zulia', 'game por apuesta');
ok(recibo.lines[2].amountUsd === 2, 'amountUsd de la apuesta');
// Totales SOLO desde respuestas de pago (nunca de detalles).
ok(recibo.premioTotalBs === 300 && recibo.premioTotalUsd === 0, `total Bs desde POST /pagos (${recibo.premioTotalBs})`);
// Ticket ya pagado + Imprimir (sin lote): estados reales → PAGADA previa.
const reciboPagado = buildReciboLines(
  { apuestas: [{ estado: 'pagada', combinacion: { animal: 'Perro', numero: 14 } }] },
  []
);
ok(reciboPagado.lines[0].estado === 'PAGADA', 'apuesta ya pagada (ticket pagado) → PAGADA sin lote');
ok(reciboPagado.premioTotalBs === 0 && reciboPagado.premioTotalUsd === 0, 'sin lote → totales 0 (no se recalculan de detalles)');
// Vacio / defensivos.
ok(buildReciboLines(null, []).lines.length === 0, 'ticket nulo → 0 líneas');
ok(buildReciboLines({}, null).lines.length === 0 && buildReciboLines({}, null).premioTotalBs === 0, 'ticket sin apuestas → 0/0');
ok(
  buildReciboLines({ apuestas: [{ combinacion: 'json-invalido', estado: 'ganadora' }] }, [null]).lines[0].jugada === '-',
  'combinacion inválida → jugada «-» sin crash',
);
ok(estadoRecibo('ganadora') === 'GANADA' && estadoRecibo('perdida') === 'PERDIDA', 'estadoRecibo: mapa directo');
ok(estadoRecibo('pagada') === 'PAGADA' && estadoRecibo(null) === 'PENDIENTE' && estadoRecibo('anulada') === 'PENDIENTE', 'estadoRecibo: fuera del mapa → PENDIENTE');

console.log('\n== S5 reimpresión: montos REALES desde GET /pagos/{apuesta} (map por apuesta) ==');
// La reimpresión de un ticket pagado consulta los pagos registrados por
// apuesta y los pasa al builder; el comprobante muestra el monto REAL pagado
// por jugada y totales correctos (antes: 0 por falta de respuestas POST).
const reprint = buildReciboLines(ticketRecibo, [], {
  1: [{ amount_bs: '300.00', amount_usd: '0.00', tipo: 'egreso', moneda: 'bs' }],
});
ok(reprint.lines[0].estado === 'PAGADA', 'reimpresión: jugada con pago registrado → PAGADA');
ok(
  reprint.lines[0].premioBs === 300 && reprint.lines[0].premioUsd === 0,
  `reimpresión: premio por jugada = monto REAL pagado (decimal:2 string) (${reprint.lines[0].premioBs})`,
);
ok(reprint.lines[1].estado === 'PERDIDA' && reprint.lines[2].estado === 'PENDIENTE', 'reimpresión: jugadas sin pago conservan su estado');
ok(reprint.lines[1].premioBs === 0 && reprint.lines[2].premioBs === 0, 'reimpresión: jugada sin pago → premio 0');
ok(reprint.premioTotalBs === 300 && reprint.premioTotalUsd === 0, `reimpresión: total Bs desde pagos registrados (${reprint.premioTotalBs})`);
// Varios pagos por apuesta (pagos parciales) → se suman por moneda.
const reprintMulti = buildReciboLines(
  { apuestas: [{ id: 7, estado: 'ganadora', combinacion: { animal: 'Perro', numero: 1 } }] },
  [],
  { 7: [{ amount_bs: 100, amount_usd: 0 }, { amount_bs: 50, amount_usd: 2.5 }] },
);
ok(reprintMulti.lines[0].premioBs === 150 && reprintMulti.lines[0].premioUsd === 2.5, 'varios pagos por apuesta → se suman por moneda');
ok(reprintMulti.premioTotalBs === 150 && reprintMulti.premioTotalUsd === 2.5, 'totales = suma de pagos registrados');
// Fallback: GET falló / sin pagos → estados reales, totales 0, sin crash
// (el reciboPagado previo ya cubre "sin lote → totales 0").
ok(reciboPagado.premioTotalBs === 0 && reciboPagado.premioTotalUsd === 0, 'reimpresión sin datos de pago → totales 0 (fallback, no bloquea)');
// data: [] → no cuenta como pago registrado.
const reprintVacio = buildReciboLines(
  { apuestas: [{ id: 1, estado: 'ganadora', combinacion: { animal: 'Perro', numero: 14 } }] },
  [],
  { 1: [] },
);
ok(reprintVacio.lines[0].estado === 'GANADA' && reprintVacio.premioTotalBs === 0, 'data: [] → no es pago registrado (estado real, totales 0)');
// Sin map (tercer arg omitido) → mismo comportamiento que antes (retrocompat).
ok(buildReciboLines(ticketRecibo, [null, null, null]).premioTotalBs === 0, 'tercer arg omitido → comportamiento previo intacto');

console.log('\n== TQ-10 U3: vistaUpdate (aviso obligatorio de instalación) ==');
// Puro: el aviso es visible SOLO con la actualización descargada; postergable
// SOLO con venta/ticket en curso (spec instalacion: sin venta no se posterga).
ok(
  vistaUpdate('downloaded', true).visible === true && vistaUpdate('downloaded', true).puedePostergar === true,
  'descargado + venta en curso → visible y postergable',
);
ok(
  vistaUpdate('downloaded', false).visible === true && vistaUpdate('downloaded', false).puedePostergar === false,
  'descargado sin venta → visible pero NO postergable (spec)',
);
ok(vistaUpdate('downloading', true).visible === false, 'descargando → sin aviso de instalación (badge de progreso)');
ok(vistaUpdate('checking', false).visible === false, 'chequeando → sin aviso');
ok(vistaUpdate('error', true).visible === false, 'error → sin aviso bloqueante (spec: la app sigue operando)');
ok(vistaUpdate('idle', false).visible === false, 'idle → sin aviso');

console.log('\n== tipo-pago-venta: catálogo y derivación de opciones por moneda (R2) ==');
// Catálogo TIPOS_PAGO: 4 opciones Bs + «USD efectivo» (design §Interfaces).
ok(TIPOS_PAGO.length === 5, `TIPOS_PAGO: 5 entradas (4 Bs + «USD efectivo») (${TIPOS_PAGO.length})`);
ok(TIPOS_PAGO.filter((o) => o.moneda === 'usd').length === 1, 'TIPOS_PAGO: una sola entrada de moneda usd');
const opcBs = opcionesPago('bs');
ok(opcBs.length === 4, `Bs → 4 opciones (${opcBs.length})`);
ok(
  JSON.stringify(opcBs.map((o) => o.codigo)) === JSON.stringify(['transferencia', 'efectivo', 'punto_venta', 'pago_movil']),
  `Bs: los 4 códigos del enum backend (${opcBs.map((o) => o.codigo).join(',')})`,
);
ok(opcBs.every((o) => o.moneda === 'bs'), 'Bs: todas las opciones con moneda bs');
ok(opcBs.every((o) => typeof o.label === 'string' && o.label.length > 0), 'Bs: toda opción con label no vacío');
const opcUsd = opcionesPago('usd');
ok(opcUsd.length === 1, `USD → 1 opción (${opcUsd.length})`);
ok(
  opcUsd[0].codigo === 'efectivo' && opcUsd[0].label === 'USD efectivo' && opcUsd[0].moneda === 'usd',
  `USD: único «USD efectivo» con código efectivo (${opcUsd[0].label})`,
);
ok(opcionesPago('mixto').length === 0, 'mixto → sin opciones (no hay combinación mixta)');
ok(opcionesPago(null).length === 0, 'null → sin opciones');

console.log('\n== tipo-pago-venta: monedaDelTicket (R3, moneda única) ==');
ok(monedaDelTicket([]) === null, 'sin líneas → null');
ok(monedaDelTicket(null) === null, 'líneas null → null (defensivo)');
ok(monedaDelTicket([{ moneda: 'bs' }]) === 'bs', 'solo Bs → bs');
ok(monedaDelTicket([{ moneda: 'usd' }]) === 'usd', 'solo $ → usd');
ok(monedaDelTicket([{ moneda: 'bs' }, { moneda: 'usd' }]) === 'mixto', 'Bs + $ → mixto');
// Borde (design §Interfaces): moneda ausente/desconocida cuenta como bs.
ok(monedaDelTicket([{ moneda: null }]) === 'bs', 'moneda null → bs (moneda base)');
ok(monedaDelTicket([{}]) === 'bs', 'línea sin moneda → bs');
ok(monedaDelTicket([{ moneda: 'eur' }]) === 'bs', 'moneda desconocida → bs');
ok(monedaDelTicket([{ moneda: 'bs' }, { moneda: undefined }]) === 'bs', 'Bs + ausente → bs (no mixto)');

console.log('\n== tipo-pago-venta: hayMezclaDeMonedas (R3, guarda defensiva) ==');
ok(hayMezclaDeMonedas([{ moneda: 'bs' }, { moneda: 'usd' }]) === true, 'Bs + $ → hay mezcla');
ok(hayMezclaDeMonedas([{ moneda: 'bs' }, { moneda: 'bs' }]) === false, 'solo Bs → sin mezcla');
ok(hayMezclaDeMonedas([{ moneda: 'usd' }]) === false, 'solo $ → sin mezcla');
ok(hayMezclaDeMonedas([]) === false, 'sin líneas → sin mezcla');
ok(hayMezclaDeMonedas(null) === false, 'null → sin mezcla (defensivo)');

console.log('\n== tipo-pago-venta: metodoValido (R6, obligatoriedad) ==');
ok(metodoValido('bs', 'transferencia') === true, 'Bs + transferencia → válido');
ok(metodoValido('bs', 'efectivo') === true, 'Bs + efectivo → válido');
ok(metodoValido('usd', 'efectivo') === true, 'USD + efectivo → válido');
ok(metodoValido('usd', 'transferencia') === false, 'USD + transferencia → inválido (no es opción USD)');
ok(metodoValido('bs', 'otro') === false, 'código fuera del enum → inválido');
ok(metodoValido('mixto', 'efectivo') === false, 'mixto → ningún método válido');
ok(metodoValido('bs', null) === false && metodoValido('bs', '') === false, 'sin método → inválido');

console.log('\n== tipo-pago-venta: labelTipoPago (D5: efectivo cambia por moneda) ==');
ok(labelTipoPago('efectivo', 'bs') === 'Efectivo', `efectivo en Bs → «Efectivo» (${labelTipoPago('efectivo', 'bs')})`);
ok(labelTipoPago('efectivo', 'usd') === 'USD efectivo', `efectivo en USD → «USD efectivo» (${labelTipoPago('efectivo', 'usd')})`);
ok(labelTipoPago('pago_movil', 'bs') === 'Pago móvil', 'pago_movil en Bs → «Pago móvil»');
ok(labelTipoPago('transferencia', 'usd') === null, 'transferencia en USD → null (no es opción)');
ok(labelTipoPago(null, 'bs') === null, 'sin método → null');

console.log(`\n${checks} checks, ${fallos} fallos`);
process.exit(fallos === 0 ? 0 : 1);
