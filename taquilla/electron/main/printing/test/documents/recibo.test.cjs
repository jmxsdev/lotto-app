'use strict';

const { test } = require('node:test');
const assert = require('node:assert/strict');
const { render, buildLines } = require('../../dist/documents/recibo.js');

const CFG_32 = { columns: 32, codePage: 'cp850', cut: 'partial', feedLines: 3 };
const CFG_48 = { columns: 48, codePage: 'cp850', cut: 'partial', feedLines: 3 };
const rule = (width) => '-'.repeat(width);
const spaces = (count) => ' '.repeat(count);

const recibo = {
  ticketCode: 'A1B2C3',
  date: '10/11/2026',
  time: '09:15:00',
  lines: [
    { game: 'Aguila', jugada: 'Perro #14', estado: 'PAGADA', amountBs: 100, amountUsd: 0, premioBs: 500, premioUsd: 0 },
    { game: 'Tripleta Zodiacal', jugada: 'Triple C #157 Sagitario', estado: 'PENDIENTE', amountBs: 50, amountUsd: 2.5, premioBs: 0, premioUsd: 0 },
  ],
  premioTotalBs: 500,
  premioTotalUsd: 0,
};

test('golden 32 columnas: bloque apilado de 8 campos, estados y totales', () => {
  assert.deepEqual(buildLines(recibo, CFG_32), [
    spaces(6) + 'COMPROBANTE DE PAGO' + spaces(7),
    spaces(13) + 'A1B2C3' + spaces(13),
    rule(32),
    'Fecha: 10/11/2026 09:15',
    rule(32),
    '  1 Aguila',
    '    Jugada : Perro #14',
    '    Estado : PAGADA',
    '    Monto  : Bs 100,00 / $ 0,00',
    '    Premio : Bs 500,00 / $ 0,00',
    '  2 Tripleta Zodiacal',
    '    Jugada : Triple C #157',
    spaces(13) + 'Sagitario',
    '    Estado : PENDIENTE',
    '    Monto  : Bs 50,00 / $ 2,50',
    '    Premio : Bs 0,00 / $ 0,00',
    rule(32),
    'Total premio BS:' + spaces(6) + 'Bs. 500,00',
    'Total premio USD:' + spaces(9) + '$ 0,00',
    rule(32),
    spaces(4) + 'Premio pagado - Gracias!' + spaces(4),
  ]);
});

test('32 columnas: montos de 13 caracteres separan la pareja y los totales apilan sin truncar', () => {
  const millon = {
    ticketCode: 'M1M2M3',
    date: '10/11/2026',
    time: '11:00:00',
    lines: [{ game: 'Aguila', jugada: 'Perro #14', estado: 'GANADA', amountBs: 1234567.89, amountUsd: 1234567.89, premioBs: 1234567.89, premioUsd: 1234567.89 }],
    premioTotalBs: 1234567.89,
    premioTotalUsd: 1234567.89,
  };
  const lines = buildLines(millon, CFG_32);
  assert.equal(lines[8], '    Monto  : Bs 1.234.567,89');
  assert.equal(lines[9], spaces(13) + '$ 1.234.567,89');
  assert.equal(lines[10], '    Premio : Bs 1.234.567,89');
  assert.equal(lines[11], spaces(13) + '$ 1.234.567,89');
  assert.equal(lines[13], 'Total premio BS:');
  assert.equal(lines[14], '  Bs. 1.234.567,89');
  assert.equal(lines[15], 'Total premio USD:');
  assert.equal(lines[16], '  $ 1.234.567,89');
  assert.ok(lines.every((line) => line.length <= 32));
  const texto = lines.join('\n');
  assert.equal(texto.split('1.234.567,89').length - 1, 6, 'los cuatro montos y los dos totales conservan el valor completo');
});

test('32 columnas: los cuatro estados se imprimen y el total usa el payload, no una suma', () => {
  const estados = {
    ticketCode: 'E1E2E3',
    date: '10/11/2026',
    time: '12:00:00',
    lines: [
      { game: 'Aguila', jugada: 'Perro #14', estado: 'GANADA', amountBs: 10, amountUsd: 0, premioBs: 0, premioUsd: 0 },
      { game: 'Aguila', jugada: 'Gato #07', estado: 'PERDIDA', amountBs: 10, amountUsd: 0, premioBs: 0, premioUsd: 0 },
      { game: 'Aguila', jugada: 'Zorro #21', estado: 'PENDIENTE', amountBs: 10, amountUsd: 0, premioBs: 0, premioUsd: 0 },
      { game: 'Aguila', jugada: 'Leon #03', estado: 'PAGADA', amountBs: 10, amountUsd: 0, premioBs: 0, premioUsd: 0 },
    ],
    premioTotalBs: 777.5,
    premioTotalUsd: 12.25,
  };
  const lines = buildLines(estados, CFG_32);
  assert.deepEqual(lines.filter((line) => line.startsWith('    Estado')), [
    '    Estado : GANADA',
    '    Estado : PERDIDA',
    '    Estado : PENDIENTE',
    '    Estado : PAGADA',
  ]);
  assert.equal(lines[lines.length - 4], 'Total premio BS:' + spaces(6) + 'Bs. 777,50');
  assert.equal(lines[lines.length - 3], 'Total premio USD:' + spaces(8) + '$ 12,25');
});

test('48 columnas (opt-in): bloque apilado con montos alineados y totales sin truncar', () => {
  const lines = buildLines(recibo, CFG_48);
  assert.equal(lines[0], spaces(14) + 'COMPROBANTE DE PAGO' + spaces(15));
  assert.equal(lines[1], spaces(21) + 'A1B2C3' + spaces(21));
  assert.equal(lines[5], '  1 Aguila');
  assert.equal(lines[6], '    Jugada : Perro #14');
  assert.equal(lines[8], '    Monto  : Bs 100,00' + spaces(12) + '$ 0,00');
  assert.equal(lines[9], '    Premio : Bs 500,00' + spaces(12) + '$ 0,00');
  assert.equal(lines[11], '    Jugada : Triple C #157 Sagitario');
  assert.equal(lines[13], '    Monto  : Bs 50,00' + spaces(13) + '$ 2,50');
  assert.equal(lines[16], 'Total premio BS:' + spaces(22) + 'Bs. 500,00');
  assert.equal(lines[19], spaces(12) + 'Premio pagado - Gracias!' + spaces(12));
  assert.ok(lines.every((line) => line.length <= 48));
});

test('32 columnas: juego largo se envuelve con continuación indentada 4 espacios', () => {
  const data = {
    ticketCode: 'L1L2L3',
    date: '10/11/2026',
    time: '13:45:00',
    lines: [{ game: 'Tripleta Zodiacal Extendida Dorada', jugada: 'Triple C #157', estado: 'GANADA', amountBs: 10, amountUsd: 0, premioBs: 0, premioUsd: 0 }],
    premioTotalBs: 0,
    premioTotalUsd: 0,
  };
  const lines = buildLines(data, CFG_32);
  assert.equal(lines[5], '  1 Tripleta Zodiacal Extendida');
  assert.equal(lines[6], '    Dorada');
  assert.equal(lines[7], '    Jugada : Triple C #157');
  assert.ok(lines.every((line) => line.length <= 32));
});

test('render usa doble altura para el título y cierra con feed 3 y corte parcial', () => {
  const hex = render(recibo, CFG_32).toString('hex');
  assert.ok(hex.includes('1d2101'), 'debe activar doble altura');
  assert.ok(hex.includes('1d2100'), 'debe restaurar la altura');
  assert.equal(hex.includes('1d2110'), false, 'nunca debe emitir doble ancho');
  assert.ok(hex.includes('1b6101'), 'el título va centrado');
  assert.ok(hex.includes('1b7402'), 'cp850 se selecciona con ESC t 2');
  assert.ok(hex.endsWith('1b64031d564200'), 'cierra con feed 3 y corte parcial');
});
