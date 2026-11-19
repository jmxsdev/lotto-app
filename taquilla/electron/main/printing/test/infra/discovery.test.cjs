'use strict';

const { test } = require('node:test');
const assert = require('node:assert/strict');
const discovery = require('../../dist/infra/discovery.js');
const { listPrinters, resolvePrinter } = discovery;

const queues = (...entries) => async () => entries.map((entry) => (typeof entry === 'string' ? { name: entry } : entry));
const hasCode = (code) => (error) => error.code === code;

test('listPrinters conserva name, displayName, description, options y campos extra', async () => {
  const entry = {
    name: 'POS-58', displayName: 'POS 58', description: 'Termica',
    options: { 'printer-is-default': 'true' }, status: 3,
  };
  const result = await listPrinters({ getPrintersAsync: queues(entry) });
  assert.deepEqual(result.printers, [entry]);
  assert.equal(result.suggested, 'POS-58');
});

test('listPrinters devuelve una lista vacia y suggested null sin impresoras', async () => {
  const result = await listPrinters({ getPrintersAsync: queues() });
  assert.deepEqual(result, { printers: [], suggested: null });
});

test('listPrinters sugiere la unica impresora enumerada', async () => {
  const result = await listPrinters({ getPrintersAsync: queues('Caja 1') });
  assert.equal(result.suggested, 'Caja 1');
});

test('listPrinters sugiere la primera coincidencia por token en name o displayName', async () => {
  const byName = await listPrinters({ getPrintersAsync: queues('Otra', 'POS-58', 'TM-T20') });
  assert.equal(byName.suggested, 'POS-58');
  const byDisplayName = await listPrinters({ getPrintersAsync: queues({ name: 'Caja', displayName: 'Thermal Receipt' }, 'Otra') });
  assert.equal(byDisplayName.suggested, 'Caja');
});

test('listPrinters devuelve suggested null cuando ninguna impresora coincide', async () => {
  const result = await listPrinters({ getPrintersAsync: queues('Almacen', 'Oficina') });
  assert.equal(result.suggested, null);
  assert.deepEqual(result.printers.map((printer) => printer.name), ['Almacen', 'Oficina']);
  assert.equal('saveConfig' in discovery, false, 'la lista nunca persiste');
});

test('listPrinters y resolvePrinter mapean rechazo o timeout a E_DISCOVERY_TIMEOUT', async () => {
  const details = (error) => error.code === 'E_DISCOVERY_TIMEOUT'
    && Array.isArray(error.details.available) && error.details.available.length === 0
    && error.details.suggested === null;
  await assert.rejects(listPrinters({ getPrintersAsync: async () => { throw new Error('sin servicio'); } }), details);
  await assert.rejects(listPrinters({ getPrintersAsync: () => new Promise(() => {}), timeoutMs: 10 }), details);
  await assert.rejects(resolvePrinter({ printerName: 'POS-58', getPrintersAsync: async () => { throw new Error('sin servicio'); } }), hasCode('E_DISCOVERY_TIMEOUT'));
  await assert.rejects(resolvePrinter({ printerName: 'POS-58', getPrintersAsync: () => new Promise(() => {}), timeoutMs: 10 }), hasCode('E_DISCOVERY_TIMEOUT'));
});

test('resuelve la cola por coincidencia exacta y luego sin distinguir mayusculas', async () => {
  assert.deepEqual(await resolvePrinter({ printerName: 'POS-58', getPrintersAsync: queues('POS-58', 'POS-80') }), { name: 'POS-58' });
  assert.deepEqual(await resolvePrinter({ printerName: 'pos-58', getPrintersAsync: queues('POS-58') }), { name: 'POS-58' });
});

test('no hace coincidencia por subcadena y el error incluye available y suggested', async () => {
  await assert.rejects(
    resolvePrinter({ printerName: 'POS', getPrintersAsync: queues('PostScript', 'POS-58') }),
    (error) => error.code === 'E_PRINTER_NOT_FOUND'
      && error.details.available.join(',') === 'PostScript,POS-58'
      && error.details.suggested === 'PostScript'
      && /Disponibles: PostScript, POS-58/.test(error.message),
  );
});

test('una lista vacia o una cola ausente lista las impresoras disponibles', async () => {
  await assert.rejects(
    resolvePrinter({ printerName: 'POS-58', getPrintersAsync: queues() }),
    (error) => error.code === 'E_PRINTER_NOT_FOUND' && /No hay impresoras instaladas/.test(error.message)
      && error.details.available.length === 0 && error.details.suggested === null,
  );
  await assert.rejects(
    resolvePrinter({ printerName: 'POS-58', getPrintersAsync: queues('A', 'B') }),
    (error) => error.code === 'E_PRINTER_NOT_FOUND' && /Disponibles: A, B/.test(error.message)
      && error.details.available.join(',') === 'A,B' && error.details.suggested === null,
  );
});

test('rechaza nombres con controles, saltos de linea o longitud excesiva sin enumerar', async () => {
  const invalid = ['POS\nX', 'POS\rX', 'POS\tX', 'POS\u0007', 'A'.repeat(129)];
  for (const name of invalid) {
    let enumerated = false;
    const getPrintersAsync = async () => { enumerated = true; return [{ name }]; };
    await assert.rejects(resolvePrinter({ printerName: name, getPrintersAsync }), hasCode('E_CONFIG_INVALID'), `debe rechazar ${JSON.stringify(name)}`);
    assert.equal(enumerated, false, 'no debe enumerar con un nombre invalido');
  }
});

test('acepta parentesis y otros imprimibles solo si la cola esta enumerada', async () => {
  const name = 'POS-58 (Copy 1)';
  assert.deepEqual(await resolvePrinter({ printerName: name, getPrintersAsync: queues(name) }), { name });
  assert.deepEqual(await resolvePrinter({ printerName: 'POS;rm', getPrintersAsync: queues('POS;rm') }), { name: 'POS;rm' });
  await assert.rejects(resolvePrinter({ printerName: 'POS;rm', getPrintersAsync: queues('POS-58') }), hasCode('E_PRINTER_NOT_FOUND'));
});

test('un nombre ausente produce E_PRINTER_UNCONFIGURED con available y suggested', async () => {
  await assert.rejects(
    resolvePrinter({ printerName: null, getPrintersAsync: queues('A', 'B') }),
    (error) => error.code === 'E_PRINTER_UNCONFIGURED'
      && error.details.available.join(',') === 'A,B' && error.details.suggested === null
      && !new RegExp(`${['LOTTO', '_'].join('')}|variable`, 'i').test(error.message),
  );
  await assert.rejects(resolvePrinter({ printerName: '   ', getPrintersAsync: queues() }), hasCode('E_PRINTER_UNCONFIGURED'));
});

test('acepta nombres Unicode con los signos permitidos', async () => {
  const name = 'Caja Ñandú 1-Printer_#2, A.B';
  assert.deepEqual(await resolvePrinter({ printerName: name, getPrintersAsync: queues(name) }), { name });
});
