'use strict';

const { test } = require('node:test');
const assert = require('node:assert/strict');
const { formatMoney, formatDate, formatTime, normalizeDateText, normalizeTimeText } = require('../../dist/core/format.js');

test('formatMoney redondeo hacia arriba, miles con punto y decimales con coma', () => {
  [
    [0, '0,00'], [1.5, '1,50'], [1.005, '1,01'], [2.675, '2,68'], [1234.567, '1.234,57'],
    [1234567.891, '1.234.567,89'], [-12.5, '-12,50'], [-0, '0,00'], [NaN, '0,00'], [undefined, '0,00'],
  ].forEach(([value, expected]) => assert.equal(formatMoney(value), expected));
});

test('formatDate desplaza instantes ISO a Caracas (-04) y deja intacta la entrada solo de fecha', () => {
  assert.equal(formatDate('2026-11-09T18:30:00.000Z'), '09/11/2026');
  assert.equal(formatDate('2026-11-10T02:00:00.000Z'), '09/11/2026');
  assert.equal(formatDate('2026-11-09'), '09/11/2026');
  assert.equal(formatDate('2026-11-09 14:30:00'), '09/11/2026');
  assert.equal(formatDate('sin fecha'), 'sin fecha');
});

test('formatTime maneja Z, microsegundos, hora local y texto de 12 h', () => {
  [
    ['2026-11-09T18:30:00.000Z', '14:30'], ['2026-11-09T14:30:00.000000Z', '10:30'],
    ['2026-11-09 14:30:00', '14:30'], ['2026-11-09', ''], ['2:30:15 p. m.', '14:30'],
    ['12:00 a. m.', '00:00'], ['12:00 p. m.', '12:00'], ['sin hora', ''],
  ].forEach(([value, expected]) => assert.equal(formatTime(value), expected));
});

test('el texto localizado de fecha y hora se normaliza de forma determinista', () => {
  assert.equal(normalizeDateText('9/11/2026'), '09/11/2026');
  assert.equal(normalizeDateText('10/11/2026'), '10/11/2026');
  assert.equal(normalizeTimeText('10:30:15 a. m.'), '10:30');
  assert.equal(normalizeTimeText('2:30:15 p. m.'), '14:30');
});
