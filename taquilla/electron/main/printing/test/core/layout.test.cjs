'use strict';

const { test } = require('node:test');
const assert = require('node:assert/strict');
const { pad, truncate, wrap, row, rule, kv, stacked, cpLen } = require('../../dist/core/layout.js');

test('pad tiene ancho exacto y soporta alineación', () => {
  assert.equal(pad('ab', 5), 'ab   ');
  assert.equal(pad('ab', 5, 'right'), '   ab');
  assert.equal(pad('ab', 5, 'center'), ' ab  ');
  assert.equal(pad('abcdef', 3), 'abc');
  assert.equal(cpLen(pad('😀b', 2)), 2);
  assert.throws(() => pad('x', -1), RangeError);
});

test('truncate/wrap son seguros por punto de código y dividen palabras largas', () => {
  assert.equal(truncate('a😀b', 2), 'a😀');
  assert.equal(cpLen(truncate('a😀b', 2)), 2);
  assert.deepEqual(wrap('hello world', 5), ['hello', 'world']);
  assert.deepEqual(wrap('abcdefghij', 4), ['abcd', 'efgh', 'ij']);
  assert.deepEqual(wrap('ab cdefghij', 4), ['ab', 'cdef', 'ghij']);
  assert.deepEqual(wrap('😀😀😀', 2), ['😀😀', '😀']);
  assert.deepEqual(wrap('', 8), ['']);
});

test('row valida sum(widths) === columns y longitudes iguales', () => {
  assert.equal(row(['a', 'b'], [3, 5], ['left', 'right'], 8), 'a      b');
  assert.equal(cpLen(row(['a', 'b'], [3, 5], ['left', 'right'], 8)), 8);
  assert.throws(() => row(['a'], [3], [], 4), /columns/);
  assert.throws(() => row(['a', 'b'], [3], [], 3), /equal length/);
});

test('rule, kv y stacked se mantienen dentro del presupuesto de columnas', () => {
  assert.equal(rule(5), '-----');
  assert.equal(rule(3, '='), '===');
  const line = kv('Total:', '10,00', 16);
  assert.equal(cpLen(line), 16);
  assert.ok(line.startsWith('Total:') && line.endsWith('10,00'));
  assert.deepEqual(stacked('Total premio BS:', 'Bs. 1.234.567,89', 32), ['Total premio BS:', '  Bs. 1.234.567,89']);
  const wrapped = stacked('Total premio BS:', 'Bs. 1.234.567,89 y algo mas largo', 20, 2);
  assert.ok(wrapped.slice(1).every((l) => l.startsWith('  ') && cpLen(l) <= 20));
});
