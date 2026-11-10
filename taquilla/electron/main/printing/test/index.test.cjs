'use strict';

const { test } = require('node:test');
const assert = require('node:assert/strict');
const { createPrinting } = require('../dist/index.js');
const printTest = require('../dist/documents/printTest.js');

const missingFile = () => {
  const error = new Error('missing');
  error.code = 'ENOENT';
  throw error;
};
const ticketData = {
  ticketCode: 'A1B2C3',
  date: '09/11/2026',
  time: '14:30:15',
  game: 'Animalitos',
  lines: [{ game: 'Animalitos', jugada: 'Perro #14', amountBs: 150, amountUsd: 0 }],
};
const reciboData = {
  kind: 'recibo',
  ticketCode: 'R1C2B3',
  date: '10/11/2026',
  time: '09:15',
  lines: [{ game: 'Aguila', jugada: 'Perro #14', estado: 'PAGADA', amountBs: 100, amountUsd: 0, premioBs: 500, premioUsd: 0 }],
  premioTotalBs: 500,
  premioTotalUsd: 0,
};
const webContents = { getPrintersAsync: async () => [{ name: 'POS-58' }] };
const cierreData = {
  id: 42,
  taquillaNombre: 'Taquilla 1',
  fecha_inicio: '2026-11-09T12:00:00Z',
  fecha_fin: '2026-11-09T22:00:00Z',
  exchange_rate_cierre: 40,
  total_ventas_bs: 100, total_ventas_usd: 5,
  total_egresos_bs: 10, total_egresos_usd: 1,
  total_efectivo_bs: 90, total_efectivo_usd: 4,
  desglose_metodos: { bs: {}, usd: {} },
};
const reporteData = {
  taquillaNombre: 'Taquilla 1',
  fecha_desde: '2026-11-01',
  fecha_hasta: '2026-11-07',
  ventana_cubierta: { cierres_incluidos: 1 },
  total_ventas_bs: 100, total_ventas_usd: 5,
  cierres: [{ id: 42, taquilla_id: 3 }],
  desglose_metodos: { bs: {}, usd: {} },
};
const create = (overrides = {}) => createPrinting({
  env: { LOTTO_PRINTER_NAME: 'POS-58' },
  userDataPath: '/tmp/lotto',
  readFile: missingFile,
  sendRaw: async () => {},
  ...overrides,
});

test('ticket exitoso envia el buffer y devuelve el mensaje exacto', async () => {
  const sent = [];
  const printing = create({ sendRaw: async (name, buffer) => sent.push({ name, buffer }) });
  const config = printing.resolveEffectiveConfig();
  const result = await printing.printDocument({ kind: 'ticket', data: ticketData, webContents });
  assert.deepEqual(result, { success: true, message: 'Ticket enviado a impresora' });
  assert.equal(sent.length, 1);
  assert.equal(sent[0].name, 'POS-58');
  assert.ok(Buffer.isBuffer(sent[0].buffer));
  assert.equal(
    sent[0].buffer.toString('hex'),
    printing.buildBuffer({ kind: 'ticket', data: ticketData, config }).toString('hex'),
  );
});

test('recibo usa el builder nativo y devuelve el mensaje de Ticket', async () => {
  const sent = [];
  const printing = create({ sendRaw: async (name, buffer) => sent.push({ name, buffer }) });
  const result = await printing.printDocument({
    kind: 'recibo',
    data: reciboData,
    webContents,
  });
  assert.deepEqual(result, { success: true, message: 'Ticket enviado a impresora' });
  assert.equal(sent.length, 1);
  assert.equal(sent[0].name, 'POS-58');
  assert.equal(
    sent[0].buffer.toString('hex'),
    printing.buildBuffer({ kind: 'recibo', data: reciboData, config: printing.resolveEffectiveConfig() }).toString('hex'),
  );
});

test('cierre y reporte usan el builder nativo con su mensaje exacto', async () => {
  const sent = [];
  const printing = create({ sendRaw: async (name, buffer) => sent.push({ name, buffer }) });
  const cases = [
    ['cierre', cierreData, 'Cierre enviado a impresora'],
    ['reporte', reporteData, 'Reporte enviado a impresora'],
  ];
  for (const [kind, data, message] of cases) {
    const result = await printing.printDocument({ kind, data, webContents });
    assert.deepEqual(result, { success: true, message });
    assert.equal(sent[sent.length - 1].buffer.toString('hex'),
      printing.buildBuffer({ kind, data, config: printing.resolveEffectiveConfig() }).toString('hex'));
  }
  assert.equal(sent.length, 2);
});

test('un kind sin builder nativo devuelve el mensaje sin fallback HTML', async () => {
  const sent = [];
  const printing = create({ sendRaw: async () => sent.push('raw') });
  const result = await printing.printDocument({
    kind: 'manifiesto',
    data: { ...ticketData, kind: 'manifiesto' },
    webContents,
  });
  assert.deepEqual(result, { success: false, message: 'Sin builder nativo para "manifiesto".' });
  assert.equal(sent.length, 0);
});

test('los errores de descubrimiento y transporte se mapean a success false', async () => {
  const printing = create({ sendRaw: async () => { throw new Error('spooler caido'); } });
  const notFound = await printing.printDocument({
    kind: 'ticket',
    data: ticketData,
    webContents: { getPrintersAsync: async () => [{ name: 'Otra' }] },
  });
  assert.equal(notFound.success, false);
  assert.match(notFound.message, /no encontrada/);

  const transport = await printing.printDocument({ kind: 'ticket', data: ticketData, webContents });
  assert.deepEqual(transport, { success: false, message: 'spooler caido' });
});

test('un lock ocupado devuelve el mensaje de E_PRINT_BUSY sin lanzar', async () => {
  const busyLock = {
    isBusy: () => true,
    run: async () => {
      throw Object.assign(new Error('Ya hay una impresion en curso. Espere a que termine antes de reintentar.'), { code: 'E_PRINT_BUSY' });
    },
  };
  const printing = create({ lock: busyLock });
  const result = await printing.printDocument({ kind: 'ticket', data: ticketData, webContents });
  assert.equal(result.success, false);
  assert.match(result.message, /impresion en curso/);
});

test('tras un intento raw fallido no se reintenta (sin duplicado)', async () => {
  let rawCalls = 0;
  const printing = create({ sendRaw: async () => { rawCalls++; throw new Error('fallo de spooler'); } });
  const result = await printing.printDocument({
    kind: 'ticket',
    data: ticketData,
    webContents,
  });
  assert.equal(result.success, false);
  assert.match(result.message, /fallo de spooler/);
  assert.equal(rawCalls, 1);
});

test('un trabajo mayor a 512 KiB falla antes de enviar', async () => {
  const sent = [];
  const printing = create({ sendRaw: async () => sent.push('raw') });
  const huge = {
    ...ticketData,
    lines: Array.from({ length: 6000 }, () => ({ game: 'Animalitos', jugada: 'X'.repeat(60), amountBs: 1, amountUsd: 1 })),
  };
  const result = await printing.printDocument({ kind: 'ticket', data: huge, webContents });
  assert.equal(result.success, false);
  assert.match(result.message, /exceeds/);
  assert.equal(sent.length, 0);
});

test('buildBuffer rechaza tipos sin builder nativo', () => {
  const printing = create();
  assert.throws(
    () => printing.buildBuffer({ kind: 'manifiesto', data: {}, config: printing.resolveEffectiveConfig() }),
    (error) => error.code === 'E_PAYLOAD_INVALID',
  );
});

test('sin LOTTO_PRINT_TEST el arranque no envia la prueba', async () => {
  const sent = [];
  const printing = create({ sendRaw: async (...args) => sent.push(args), getWindow: () => ({ webContents }) });
  assert.equal(await printing.maybeRunStartupTest(), null);
  assert.equal(sent.length, 0);
});

test('con LOTTO_PRINT_TEST=1 envia una sola prueba', async () => {
  const sent = [];
  const printing = create({
    env: { LOTTO_PRINTER_NAME: 'POS-58', LOTTO_PRINT_TEST: '1' },
    sendRaw: async (name, buffer) => sent.push({ name, buffer }),
    getWindow: () => ({ webContents }),
  });
  const result = await printing.maybeRunStartupTest();
  assert.deepEqual(result, { success: true, message: 'Prueba de impresion enviada' });
  assert.equal(sent.length, 1);
  assert.equal(sent[0].name, 'POS-58');
  assert.equal(
    sent[0].buffer.toString('hex'),
    printTest.render(printing.resolveEffectiveConfig()).toString('hex'),
  );
});

test('sin ventana activa la prueba devuelve success false sin lanzar', async () => {
  const printing = create({
    env: { LOTTO_PRINTER_NAME: 'POS-58', LOTTO_PRINT_TEST: '1' },
    getWindow: () => null,
  });
  const result = await printing.maybeRunStartupTest();
  assert.equal(result.success, false);
  assert.match(result.message, /No hay ventana activa/);
});

test('un fallo del transporte en la prueba se devuelve como success false', async () => {
  const printing = create({
    env: { LOTTO_PRINTER_NAME: 'POS-58', LOTTO_PRINT_TEST: '1' },
    sendRaw: async () => { throw new Error('spooler caido'); },
    getWindow: () => ({ webContents }),
  });
  assert.deepEqual(await printing.maybeRunStartupTest(), { success: false, message: 'spooler caido' });
});
