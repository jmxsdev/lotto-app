'use strict';

const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { render, buildLines } = require('../../dist/documents/printTest.js');

const strings = (lines) => lines.filter((line) => typeof line === 'string');
const findDigits = (lines) => strings(lines).find((line) => line.startsWith('1234567890'));
const findMarks = (lines) => strings(lines).find((line) => line.startsWith('|'));
const ACCENTS = 'áéíóúüñÑÁÉÍÓÚ¿¡°';

test('la regla de columnas mide exactamente 32 caracteres por defecto', () => {
  const lines = buildLines();
  const digits = findDigits(lines);
  const marks = findMarks(lines);
  assert.equal(digits, '1234567890'.repeat(4).slice(0, 32));
  assert.equal(digits.length, 32);
  assert.equal(marks, '|....|....|....|....|....|....|.');
  assert.equal(marks.length, 32);
  for (let i = 0; i < 32; i += 1) assert.equal(marks[i], i % 5 === 0 ? '|' : '.');
});

test('la regla sigue el override de columnas (48 y 42)', () => {
  for (const columns of [48, 42]) {
    const lines = buildLines({ columns });
    const digits = findDigits(lines);
    const marks = findMarks(lines);
    assert.equal(digits.length, columns, `la linea de digitos mide ${columns}`);
    assert.equal(marks.length, columns, `la linea de marcas mide ${columns}`);
    assert.equal(marks[0], '|');
    assert.equal(marks[Math.floor((columns - 1) / 5) * 5], '|');
    for (let i = 0; i < columns; i += 1) assert.equal(marks[i], i % 5 === 0 ? '|' : '.');
  }
});

test('el juego espanol completo y el euro de CP858 estan presentes', () => {
  const lines = buildLines();
  const text = strings(lines).join('\n');
  for (const char of ACCENTS) assert.ok(text.includes(char), `falta el caracter ${char}`);
  const index = lines.findIndex((line) => line === 'CP858 ESC t 19');
  assert.equal(lines[index + 1], `${ACCENTS}€`, 'la linea CP858 agrega el euro');
  assert.ok(render().toString('hex').includes('d5'), 'el euro se codifica como 0xD5');
});

test('las cuatro paginas etiquetadas emiten su ESC t', () => {
  const lines = buildLines();
  const labels = strings(lines).filter((line) => /^CP\d+ ESC t \d+$/.test(line));
  assert.deepEqual(labels, ['CP437 ESC t 0', 'CP850 ESC t 2', 'CP858 ESC t 19', 'CP1252 ESC t 16']);
  const hex = render().toString('hex');
  for (const sequence of ['1b7400', '1b7402', '1b7413', '1b7410']) {
    assert.ok(hex.includes(sequence), `falta la secuencia ESC t ${sequence}`);
  }
});

test('solo usa doble altura y cierra con el feed y corte configurados', () => {
  const hex = render().toString('hex');
  assert.ok(hex.includes('1d2101'), 'activa doble altura');
  assert.ok(hex.includes('1d2100'), 'restaura la altura');
  assert.equal(hex.includes('1d2110'), false, 'nunca emite doble ancho');
  assert.ok(hex.includes('1b6101'), 'el titulo va centrado');
  assert.ok(hex.endsWith('1b64031d564200'), 'cierra con feed 3 y corte parcial');
  assert.ok(strings(buildLines({ cut: 'partial' })).some((line) => line.includes('Corte: partial')));

  const sinCorte = render({ cut: 'none' }).toString('hex');
  assert.ok(sinCorte.endsWith('1b6405'), 'sin cortador usa feed 5');
  assert.equal(sinCorte.includes('1d56'), false, 'sin cortador no emite GS V');

  const corteTotal = render({ feedLines: 5, cut: 'full' }).toString('hex');
  assert.ok(corteTotal.endsWith('1b64051d564100'), 'feed 5 y corte total');
});

test('el slip es puro: no requiere Electron ni IO', () => {
  const source = fs.readFileSync(path.join(__dirname, '..', '..', 'documents', 'printTest.ts'), 'utf8');
  assert.doesNotMatch(source, /require\(\s*['"](?:electron|fs|node:fs|node:net|electron-pos-printer)['"]\s*\)/);
});
