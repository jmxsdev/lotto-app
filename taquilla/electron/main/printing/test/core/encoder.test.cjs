'use strict';

const { test } = require('node:test');
const assert = require('node:assert/strict');
const { init, codepage, align, bold, size, feed, cut, document } = require('../../dist/core/encoder.js');

const hex = (buf) => [...buf].map((b) => b.toString(16).padStart(2, '0')).join(' ');

test('los bytes de comando coinciden con la tabla ESC/POS', () => {
  assert.equal(hex(init()), '1b 40');
  assert.deepEqual(
    ['cp437', 'cp850', 'cp858', 'cp1252'].map((page) => hex(codepage(page))),
    ['1b 74 00', '1b 74 02', '1b 74 13', '1b 74 10'],
  );
  assert.equal(codepage('ascii').length, 0);
  assert.deepEqual(['left', 'center', 'right'].map((mode) => hex(align(mode))), ['1b 61 00', '1b 61 01', '1b 61 02']);
  assert.equal(hex(bold(true)), '1b 45 01');
  assert.equal(hex(bold(false)), '1b 45 00');
  assert.equal(hex(feed(3)), '1b 64 03');
  assert.equal(hex(feed(0)), '1b 64 00');
});

test('el título usa solo doble altura, nunca doble ancho', () => {
  assert.equal(hex(size(true)), '1d 21 01');
  assert.equal(hex(size(false)), '1d 21 00');
  assert.notEqual(hex(size(true)), '1d 21 10');
});

test('modos de corte', () => {
  assert.equal(hex(cut('partial')), '1d 56 42 00');
  assert.equal(hex(cut('full')), '1d 56 41 00');
  assert.equal(cut('none').length, 0);
});

test('golden del documento completo', () => {
  const out = document({
    lines: ['TITULO', 'Linea 1', 'Linea 2'],
    config: { columns: 32, codePage: 'cp850', feedLines: 3, cut: 'partial' },
  });
  assert.equal(
    hex(out),
    '1b 40 1b 74 02 1b 61 01 1b 45 01 1d 21 01 54 49 54 55 4c 4f 0a ' +
      '1d 21 00 1b 45 00 1b 61 00 4c 69 6e 65 61 20 31 0a 4c 69 6e 65 61 20 32 0a ' +
      '1b 64 03 1d 56 42 00',
  );
  assert.equal(out.includes(Buffer.from([0x1d, 0x21, 0x10])), false);
});

test('cut none no emite GS V y usa avance final 5', () => {
  const out = document({ lines: ['X'], config: { columns: 32, codePage: 'ascii', feedLines: 3, cut: 'none' } });
  assert.equal(hex(out), '1b 40 1b 61 01 1b 45 01 1d 21 01 58 0a 1d 21 00 1b 45 00 1b 61 00 1b 64 05');
  assert.equal(out.includes(Buffer.from([0x1d, 0x56])), false);
});

test('variante de corte full y centinelas de avance/corte a mitad del documento', () => {
  const out = document({
    lines: ['A', { feed: 2 }, { cut: true }, 'B'],
    config: { columns: 32, codePage: 'cp850', feedLines: 3, cut: 'full' },
  });
  assert.ok(hex(out).includes('1b 64 02 1d 56 41 00'));
  assert.ok(hex(out).endsWith('1b 64 03 1d 56 41 00'));
});

test('el centinela de página de código cambia la secuencia y la codificación de línea', () => {
  const out = document({
    lines: ['A', { codePage: 'cp858' }, '€'],
    config: { columns: 32, codePage: 'cp850', feedLines: 0, cut: 'partial' },
  });
  assert.ok(hex(out).includes('1b 74 02'));
  assert.ok(hex(out).includes('1b 74 13'));
  assert.ok(hex(out).includes('d5 0a'));
});
