'use strict';

const { test } = require('node:test');
const assert = require('node:assert/strict');
const { resolvePrinter } = require('../../dist/infra/discovery.js');

const queues = (...names) => async () => names.map((name) => ({ name }));
const hasCode = (code) => (error) => error.code === code;

test('resuelve la cola por coincidencia exacta y luego sin distinguir mayusculas', async () => {
  assert.deepEqual(await resolvePrinter({ printerName: 'POS-58', getPrintersAsync: queues('POS-58', 'POS-80') }), { name: 'POS-58' });
  assert.deepEqual(await resolvePrinter({ printerName: 'pos-58', getPrintersAsync: queues('POS-58') }), { name: 'POS-58' });
});

test('no hace coincidencia por subcadena: PostScript nunca coincide con POS', async () => {
  await assert.rejects(
    resolvePrinter({ printerName: 'POS', getPrintersAsync: queues('PostScript', 'POS-58') }),
    (error) => error.code === 'E_PRINTER_NOT_FOUND' && /Disponibles: PostScript, POS-58/.test(error.message),
  );
});

test('una lista vacia o una cola ausente lista las impresoras disponibles', async () => {
  await assert.rejects(
    resolvePrinter({ printerName: 'POS-58', getPrintersAsync: queues() }),
    (error) => error.code === 'E_PRINTER_NOT_FOUND' && /No hay impresoras instaladas/.test(error.message),
  );
  await assert.rejects(
    resolvePrinter({ printerName: 'POS-58', getPrintersAsync: queues('A', 'B') }),
    (error) => error.code === 'E_PRINTER_NOT_FOUND' && /Disponibles: A, B/.test(error.message),
  );
});

test('rechaza metacaracteres aunque una cola enumerada coincida', async () => {
  const invalid = ['POS;rm', 'POS"x', 'POS&', 'POS|x', 'POS`x', 'POS$x', 'POS(x)', 'POS[x]', 'POS/x', 'POS:x', 'POS\nX'];
  for (const name of invalid) {
    let enumerated = false;
    const getPrintersAsync = async () => { enumerated = true; return [{ name }]; };
    await assert.rejects(resolvePrinter({ printerName: name, getPrintersAsync }), hasCode('E_CONFIG_INVALID'), `debe rechazar ${JSON.stringify(name)}`);
    assert.equal(enumerated, false, 'no debe enumerar con un nombre invalido');
  }
});

test('un nombre ausente produce E_PRINTER_UNCONFIGURED y lista las colas', async () => {
  await assert.rejects(
    resolvePrinter({ printerName: null, getPrintersAsync: queues('A', 'B') }),
    (error) => error.code === 'E_PRINTER_UNCONFIGURED' && /Impresoras disponibles: A, B/.test(error.message),
  );
  await assert.rejects(resolvePrinter({ printerName: '   ', getPrintersAsync: queues() }), hasCode('E_PRINTER_UNCONFIGURED'));
});

test('un nombre demasiado largo es invalido', async () => {
  const name = 'A'.repeat(129);
  await assert.rejects(resolvePrinter({ printerName: name, getPrintersAsync: queues(name) }), hasCode('E_CONFIG_INVALID'));
});

test('un rechazo o un tiempo de espera de la enumeracion produce E_DISCOVERY_TIMEOUT', async () => {
  await assert.rejects(
    resolvePrinter({ printerName: 'POS-58', getPrintersAsync: async () => { throw new Error('sin servicio'); } }),
    hasCode('E_DISCOVERY_TIMEOUT'),
  );
  await assert.rejects(
    resolvePrinter({ printerName: 'POS-58', getPrintersAsync: () => new Promise(() => {}), timeoutMs: 10 }),
    hasCode('E_DISCOVERY_TIMEOUT'),
  );
});

test('acepta nombres con letras Unicode, digitos y los signos permitidos', async () => {
  const name = 'Caja Ñandú 1-Printer_#2, A.B';
  assert.deepEqual(await resolvePrinter({ printerName: name, getPrintersAsync: queues(name) }), { name });
});
