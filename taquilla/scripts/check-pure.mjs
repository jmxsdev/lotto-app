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
} from '../src/utils/catalogo.ts';

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

console.log(`\n${checks} checks, ${fallos} fallos`);
process.exit(fallos === 0 ? 0 : 1);