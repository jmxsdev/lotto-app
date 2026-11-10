'use strict';

const { test } = require('node:test');
const assert = require('node:assert/strict');
const { sanitize, encodeText, lineBytes, assertJobSize, MAX_LINE_CHARS, MAX_JOB_BYTES } = require('../../dist/core/encode.js');

const hex = (buf) => [...buf].map((b) => b.toString(16).padStart(2, '0')).join(' ');
const SPANISH = 'áéíóúüñÑÁÉÍÓÚ¿¡°';

test('tabla CP850/CP437 para español, un byte por carácter', () => {
  const expected = 'a0 82 a1 a2 a3 81 a4 a5 b5 90 d6 e0 e9 a8 ad f8';
  assert.equal(hex(encodeText(SPANISH, 'cp850')), expected);
  assert.equal(hex(encodeText(SPANISH, 'cp437')), expected);
  assert.equal(encodeText(SPANISH, 'cp850').length, SPANISH.length);
});

test('euro CP858 en 0xD5 y mapa CP1252', () => {
  assert.equal(hex(encodeText('€', 'cp858')), 'd5');
  assert.equal(hex(encodeText('€', 'cp850')), hex(Buffer.from('E')));
  assert.equal(hex(encodeText('áéíñóúü¿¡°', 'cp1252')), 'e1 e9 ed f1 f3 fa fc bf a1 b0');
  assert.equal(hex(encodeText('ÁÉÍÑÓÚÜ', 'cp1252')), 'c1 c9 cd d1 d3 da dc');
});

test('ascii translitera el conjunto español completo y lo desconocido se convierte en ?', () => {
  assert.equal(encodeText(SPANISH, 'ascii').toString('latin1'), 'aeiouunNAEIOU?!o');
  assert.equal(encodeText('л', 'cp850').toString('latin1'), '?');
});

test('la entrada vacía o nula se codifica en cero bytes', () => {
  assert.equal(encodeText(null, 'cp850').length, 0);
  assert.equal(lineBytes('', { columns: 32 }).length, 0);
});

test('sanitize elimina controles y convierte saltos de línea en espacios', () => {
  assert.equal(sanitize('A\u0000B\u001bC\u001cD\u001dE\u007fF\u0085G'), 'ABCDEFG');
  assert.equal(sanitize('A\r\nB'), 'A B');
  assert.equal(sanitize('A\rB'), 'A B');
  assert.equal(sanitize('A\nB'), 'A B');
  assert.equal(sanitize('A\tB'), 'A B');
  assert.equal(sanitize(null), '');
  assert.equal(sanitize(undefined), '');
});

test('lineBytes limita columnas y la red de 96 caracteres; la validación de tamaño rechaza el exceso', () => {
  assert.equal(lineBytes('x'.repeat(100), { codePage: 'cp850', columns: 32 }).length, 32);
  assert.equal(lineBytes('x'.repeat(100), { codePage: 'cp850', columns: 200 }).length, MAX_LINE_CHARS);
  assert.equal(lineBytes('ñ'.repeat(40), { codePage: 'cp850', columns: 32 }).length, 32);
  assert.equal(assertJobSize(Buffer.alloc(MAX_JOB_BYTES)), undefined);
  assert.throws(() => assertJobSize(Buffer.alloc(MAX_JOB_BYTES + 1)), (err) => err.code === 'E_PAYLOAD_TOO_LARGE');
});
