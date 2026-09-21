#!/usr/bin/env node
/**
 * Harness [Lin] del módulo puro catalogo.ts — PR1 (REQ-CL-01..03, A5).
 * Crece en PR2 (grafos de zonas) y PR3b (calcularVuelto).
 *
 * Ejecutar desde la raíz del repo:
 *   node taquilla/scripts/check-pure.mjs
 *
 * Requiere Node 24+ (type-stripping nativo para importar .ts).
 */
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import {
  cargarCatalogo,
  normalizarLabel,
  saltarPorLetra,
  buscarPorDigito,
  crearBuscadorDigitos,
  signoPorPosicion,
} from '../src/utils/catalogo.ts';
import { buildZoneGraph, routeKey, KEYMAP, esFKey, zonaHorizontal, zonaPendienteSeleccion } from '../src/utils/keyboard.ts';
import { destinoNav, NAV_GLOBAL, teclasLegend } from '../src/utils/keyboard.ts';
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
  focoEditable: false,
  zonaActual: 'juegos',
  modalAbierto: false,
  modalPropio: null,
  columnMode: 'juegos',
  tieneLineas: false,
  tieneHistorial: false,
  seleccionEnCurso: false,
  ...parcial,
});
let r = routeKey(est({ tecla: 'F5', tieneLineas: true }));
ok(r.consume && r.tipo === 'f-key' && r.ejecutable === true, 'F5 con líneas → consume, ejecutable');
r = routeKey(est({ tecla: 'F5', tieneLineas: false }));
ok(r.consume && r.tipo === 'f-key' && r.ejecutable === false, 'F5 sin líneas → consume (preventDefault) pero NO ejecuta');
r = routeKey(est({ tecla: 'F2', tieneLineas: false }));
ok(r.consume && r.ejecutable === false, 'F2 sin líneas → guarda bloquea (no-op, A11)');
r = routeKey(est({ tecla: 'F6', tieneLineas: false }));
ok(r.consume && r.ejecutable === false, 'F6 sin líneas → guarda bloquea');
r = routeKey(est({ tecla: 'F3', tieneHistorial: false }));
ok(r.consume && r.ejecutable === false, 'F3 sin historial → guarda bloquea');
r = routeKey(est({ tecla: 'F4', tieneHistorial: true }));
ok(r.consume && r.ejecutable === true, 'F4 con historial → ejecutable');
r = routeKey(est({ tecla: 'F11' }));
ok(!r.consume, 'F11 libre → no consume (REQ-KB-07)');
r = routeKey(est({ tecla: 'F5', repeat: true, tieneLineas: true }));
ok(!r.consume, 'F5 con e.repeat → se ignora (A1)');
r = routeKey(est({ tecla: 'F5', focoEditable: true, tieneLineas: true }));
ok(r.consume && r.tipo === 'f-key' && r.ejecutable, 'F5 en INPUT → sí se intercepta (única excepción F-key)');

console.log('\n== A1: routeKey — guarda de modal (REQ-KB-08) ==');
r = routeKey(est({ modalAbierto: true, tecla: 'F2', tieneLineas: true }));
ok(!r.consume, 'modal abierto + F2 → pasa (no ejecuta, KB-08)');
r = routeKey(est({ modalAbierto: true, tecla: 'F5', tieneLineas: true }));
ok(!r.consume, 'modal abierto + F5 → pasa');
r = routeKey(est({ modalAbierto: true, tecla: 'Escape' }));
ok(r.consume && r.tipo === 'escape' && r.nivel === 'modal', 'modal abierto + Esc → cierra modal');
r = routeKey(est({ modalAbierto: true, modalPropio: 'f1', tecla: 'F1' }));
ok(r.consume && r.tipo === 'toggle-modal' && r.modal === 'f1', 'modal ayuda + F1 → toggle propio');
r = routeKey(est({ modalAbierto: true, modalPropio: 'f9', tecla: 'F9' }));
ok(r.consume && r.tipo === 'toggle-modal' && r.modal === 'f9', 'modal vuelto + F9 → toggle propio');
r = routeKey(est({ modalAbierto: true, modalPropio: null, tecla: 'F1' }));
ok(!r.consume, 'modal genérico + F1 → pasa (sin toggle propio)');
r = routeKey(est({ modalAbierto: true, tecla: 'Tab' }));
ok(!r.consume, 'modal abierto + Tab → pasa');

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

console.log('\n== REQ-KB-07: KEYMAP F1–F12 ==');
ok(KEYMAP.length === 12, `KEYMAP: 12 teclas (${KEYMAP.length})`);
ok(esFKey('F1') && esFKey('F12') && esFKey('F9'), 'esFKey F1/F12/F9 → true');
ok(!esFKey('F13') && !esFKey('f1') && !esFKey('Enter'), 'esFKey no-F → false');
ok(KEYMAP.find((k) => k.tecla === 'F11').accion === null, 'F11 sin asignar (libre)');
const acciones = KEYMAP.map((k) => k.accion).filter(Boolean);
ok(acciones.length === 11, `11 acciones mapeadas (${acciones.length})`);
ok(
  KEYMAP.filter((k) => k.implementadaEn === 'PR3b').map((k) => k.tecla).join(',') === 'F1,F2,F3,F4,F5,F6,F9,F12',
  'PR3b mantiene las acciones locales del dashboard (F1-F6, F9, F12)',
);
ok(
  KEYMAP.filter((k) => k.implementadaEn === 'win-fixes').map((k) => k.tecla).join(',') === 'F7,F8,F10',
  'win-fixes mueve F7/F8/F10 a la navegación global (MainLayout, FIX-6)',
);
ok(
  KEYMAP.filter((k) => k.guarda === 'lineas').map((k) => k.tecla).join(',') === 'F2,F5,F6',
  'guardas de estado: F2/F5/F6 requieren líneas (A11)',
);
ok(
  KEYMAP.filter((k) => k.guarda === 'historial').map((k) => k.tecla).join(',') === 'F3,F4',
  'guardas de estado: F3/F4 requieren historial (A11)',
);

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

console.log('\n== PR3b: F6 selección del resumen tras eliminar (KB-07) ==');
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

console.log('\n== win-fixes FIX-6: navegación global F7/F8/F10 y Alt+D ==');
ok(NAV_GLOBAL.length === 4, `NAV_GLOBAL: 4 destinos (${NAV_GLOBAL.length})`);
ok(NAV_GLOBAL.find((n) => n.tecla === 'F7')?.ruta === '/historial', 'F7 → /historial');
ok(NAV_GLOBAL.find((n) => n.tecla === 'F8')?.ruta === '/cierre', 'F8 → /cierre');
ok(NAV_GLOBAL.find((n) => n.tecla === 'F10')?.ruta === '/resultados', 'F10 → /resultados');
ok(NAV_GLOBAL.find((n) => n.tecla === 'Alt+D')?.ruta === '/dashboard', 'Alt+D → /dashboard');
ok(destinoNav('F7', { altKey: false, ctrlKey: false })?.ruta === '/historial', 'destinoNav F7 → /historial');
ok(destinoNav('F8', { altKey: false, ctrlKey: false })?.ruta === '/cierre', 'destinoNav F8 → /cierre');
ok(destinoNav('F10', { altKey: false, ctrlKey: false })?.ruta === '/resultados', 'destinoNav F10 → /resultados');
ok(destinoNav('d', { altKey: true, ctrlKey: false })?.ruta === '/dashboard', 'destinoNav Alt+D → /dashboard');
ok(destinoNav('D', { altKey: true, ctrlKey: false })?.ruta === '/dashboard', 'destinoNav Alt+Shift+D (mayúscula) → /dashboard');
ok(destinoNav('d', { altKey: false, ctrlKey: false }) === null, 'd sin Alt → sin destino');
ok(destinoNav('d', { altKey: true, ctrlKey: true }) === null, 'Ctrl+Alt+d (AltGr) → sin destino');
ok(destinoNav('F11', { altKey: false, ctrlKey: false }) === null, 'F11 → sin destino (libre, REQ-KB-07)');
ok(destinoNav('x', { altKey: false, ctrlKey: false }) === null, 'tecla no navegable → null');
const legend = teclasLegend();
ok(legend.length === 13, `teclasLegend: 13 teclas (F1–F12 + Alt+D) (${legend.length})`);
ok(legend.some((t) => t.tecla === 'Alt+D' && t.nombre === 'Dashboard'), 'teclasLegend incluye Alt+D → Dashboard');
ok(legend.find((t) => t.tecla === 'F11')?.nombre === 'Libre', 'F11 sigue «Libre» en la leyenda');
ok(legend.find((t) => t.tecla === 'F7')?.nombre === 'Ventas', 'F7 → Ventas en la leyenda');

console.log(`\n${checks} checks, ${fallos} fallos`);
process.exit(fallos === 0 ? 0 : 1);
