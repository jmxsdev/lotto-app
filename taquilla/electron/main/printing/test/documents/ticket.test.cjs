'use strict';

const { test } = require('node:test');
const assert = require('node:assert/strict');
const { render, buildLines } = require('../../dist/documents/ticket.js');

const CFG_32 = { columns: 32, codePage: 'cp850', cut: 'partial', feedLines: 3 };
const CFG_48 = { columns: 48, codePage: 'cp850', cut: 'partial', feedLines: 3 };
const rule = (width) => '-'.repeat(width);
const spaces = (count) => ' '.repeat(count);

const single = {
  ticketCode: 'A1B2C3',
  date: '09/11/2026',
  time: '14:30:15',
  game: 'Animalitos',
  tipoPago: 'Pago movil',
  lines: [
    { game: 'Animalitos', jugada: 'Perro #14', amountBs: 150, amountUsd: 0 },
    { game: 'Animalitos', jugada: 'Gato #07', amountBs: 50, amountUsd: 0 },
  ],
};

const multi = {
  ticketCode: 'Z9Y8X7',
  date: '09/11/2026',
  time: '14:30:15',
  game: 'Animalitos, Tripleta Zodiacal',
  lines: [
    { game: 'Animalitos', jugada: 'Perro #14', sorteo: '14:30', amountBs: 100, amountUsd: 0 },
    { game: 'Animalitos', jugada: 'Gato #07', sorteo: '14:30', amountBs: 50, amountUsd: 0 },
    { game: 'Tripleta Zodiacal', jugada: 'Triple C #157 Sagitario', sorteo: '16:00', amountBs: 200, amountUsd: 0, premioBs: 999999, premioUsd: 999999 },
  ],
};

test('golden 32 columnas: apuesta en dos líneas, totales alineados y pie', () => {
  assert.deepEqual(buildLines(single, CFG_32), [
    'LOTTO TICKET',
    spaces(11) + 'Animalitos' + spaces(11),
    rule(32),
    'Ticket: A1B2C3',
    'Fecha: 09/11/2026 14:30',
    'Tipo de pago: Pago movil',
    rule(32),
    ' 1 Perro #14',
    spaces(15) + 'Bs 150,00  $ 0,00',
    ' 2 Gato #07',
    spaces(16) + 'Bs 50,00  $ 0,00',
    rule(32),
    'Total BS:' + spaces(13) + 'Bs. 200,00',
    'Total USD:' + spaces(16) + '$ 0,00',
    rule(32),
    spaces(5) + 'Gracias por su compra!' + spaces(5),
  ]);
});

test('32 columnas multijuego: filas de grupo -- juego, Hora opcional y sin premio', () => {
  const lines = buildLines(multi, CFG_32);
  assert.equal(lines[1], ' Animalitos, Tripleta Zodiacal  ');
  assert.equal(lines[6], '-- Animalitos');
  assert.equal(lines[7], ' 1 Perro #14');
  assert.equal(lines[8], '    14:30      Bs 100,00  $ 0,00');
  assert.equal(lines[11], '-- Tripleta Zodiacal');
  assert.equal(lines[13], '    16:00      Bs 200,00  $ 0,00');
  assert.equal(lines[15], 'Total BS:' + spaces(13) + 'Bs. 350,00');
  const text = lines.join('\n');
  assert.doesNotMatch(text, /premio/i);
  assert.equal(text.includes('999999'), false);
});

test('32 columnas: jugada larga se envuelve con continuación indentada 4 espacios', () => {
  const data = {
    ticketCode: 'W1W2W3',
    date: '09/11/2026',
    time: '10:15:00',
    game: 'Tripleta Zodiacal',
    lines: [{ game: 'Tripleta Zodiacal', jugada: 'Triple C #157 Sagitario Dorado', amountBs: 10, amountUsd: 0 }],
  };
  const lines = buildLines(data, CFG_32);
  assert.equal(lines[6], ' 1 Triple C #157 Sagitario');
  assert.equal(lines[7], '    Dorado');
  assert.equal(lines[8], spaces(16) + 'Bs 10,00  $ 0,00');
  assert.ok(lines.every((line) => line.length <= 32));
});

test('32 columnas: montos de 13 caracteres separan Bs y $ en líneas alineadas a la derecha', () => {
  const data = {
    ticketCode: 'M1M2M3',
    date: '09/11/2026',
    time: '11:00:00',
    game: 'Animalitos',
    lines: [{ game: 'Animalitos', jugada: 'Perro #14', amountBs: 1234567.89, amountUsd: 1234567.89 }],
  };
  const lines = buildLines(data, CFG_32);
  assert.equal(lines[6], ' 1 Perro #14');
  assert.equal(lines[7], spaces(17) + 'Bs 1.234.567,89');
  assert.equal(lines[8], spaces(18) + '$ 1.234.567,89');
  assert.equal(lines[7].length, 32);
  assert.equal(lines[8].length, 32);
});

test('48 columnas (opt-in): tabla con encabezado, grupos y montos en columnas', () => {
  const lines = buildLines(multi, CFG_48);
  assert.equal(lines[1], spaces(9) + 'Animalitos, Tripleta Zodiacal' + spaces(10));
  assert.equal(lines[6], ' #Jugada' + spaces(14) + 'Hora' + spaces(9) + 'BS' + spaces(8) + 'USD');
  assert.equal(lines[7], '-- Animalitos');
  assert.equal(lines[8], ' 1 Perro #14' + spaces(9) + '14:30' + spaces(5) + '100,00' + spaces(7) + '0,00');
  assert.equal(lines[9], ' 2 Gato #07' + spaces(10) + '14:30' + spaces(6) + '50,00' + spaces(7) + '0,00');
  assert.equal(lines[11], ' 3 Triple C #157' + spaces(5) + '16:00' + spaces(5) + '200,00' + spaces(7) + '0,00');
  assert.equal(lines[12], '   Sagitario');
  assert.equal(lines[14], 'Total BS:' + spaces(29) + 'Bs. 350,00');
  assert.equal(lines[15], 'Total USD:' + spaces(32) + '$ 0,00');
  assert.ok(lines.every((line) => line.length <= 48));
});

test('render usa doble altura para el título y no emite doble ancho', () => {
  const hex = render(single, CFG_32).toString('hex');
  assert.ok(hex.includes('1d2101'), 'debe activar doble altura');
  assert.ok(hex.includes('1d2100'), 'debe restaurar la altura');
  assert.equal(hex.includes('1d2110'), false, 'nunca debe emitir doble ancho');
  assert.ok(hex.includes('1b6101'), 'el título va centrado');
  assert.ok(hex.includes('1b7402'), 'cp850 se selecciona con ESC t 2');
  assert.ok(hex.endsWith('1b64031d564200'), 'cierra con feed 3 y corte parcial');
});
