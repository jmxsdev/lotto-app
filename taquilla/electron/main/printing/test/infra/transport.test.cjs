'use strict';

const { test } = require('node:test');
const assert = require('node:assert/strict');
const { sendRaw } = require('../../dist/infra/transport.js');

const hex = (buffer) => [...buffer].map((byte) => byte.toString(16).padStart(2, '0')).join(' ');

test('llama a sendRawCommand de forma estatica una vez con el nombre exacto y el Buffer', async () => {
  const calls = [];
  class FakePosPrinter {
    constructor() { throw new Error('no se debe instanciar'); }
    static async sendRawCommand(name, data) { calls.push([name, data]); }
  }
  await sendRaw('POS-58', Buffer.from([0x1b, 0x40, 0x41, 0x0a]), { posPrinter: FakePosPrinter });
  assert.equal(calls.length, 1);
  assert.equal(calls[0][0], 'POS-58');
  assert.ok(Buffer.isBuffer(calls[0][1]));
  assert.equal(hex(calls[0][1]), '1b 40 41 0a');
});

test('acepta un cargador inyectado', async () => {
  const calls = [];
  const fake = { sendRawCommand: async (name) => { calls.push(name); } };
  await sendRaw('POS-58', Buffer.from('x'), { posPrinter: () => fake });
  assert.deepEqual(calls, ['POS-58']);
});

test('valida el nombre y el Buffer con E_TRANSPORT_INVALID', async () => {
  const fake = { sendRawCommand: async () => {} };
  const invalid = (error) => error.code === 'E_TRANSPORT_INVALID';
  await assert.rejects(sendRaw(null, Buffer.from('x'), { posPrinter: fake }), invalid);
  await assert.rejects(sendRaw('', Buffer.from('x'), { posPrinter: fake }), invalid);
  await assert.rejects(sendRaw('POS-58', 'no es buffer', { posPrinter: fake }), invalid);
});

test('mapea el rechazo a E_TRANSPORT_FAILED sin reintentar ni caer a HTML', async () => {
  let calls = 0;
  const fake = { sendRawCommand: async () => { calls += 1; throw new Error('cola bloqueada'); } };
  await assert.rejects(
    sendRaw('POS-58', Buffer.from('x'), { posPrinter: fake }),
    (error) => error.code === 'E_TRANSPORT_FAILED' && /cola bloqueada/.test(error.message),
  );
  assert.equal(calls, 1);
});

test('mapea el tiempo de espera a E_TRANSPORT_TIMEOUT', async () => {
  const fake = { sendRawCommand: () => new Promise(() => {}) };
  await assert.rejects(
    sendRaw('POS-58', Buffer.from('x'), { posPrinter: fake, timeoutMs: 10 }),
    (error) => error.code === 'E_TRANSPORT_TIMEOUT',
  );
});

test('un modulo sin sendRawCommand produce E_TRANSPORT_UNAVAILABLE', async () => {
  await assert.rejects(
    sendRaw('POS-58', Buffer.from('x'), { posPrinter: {} }),
    (error) => error.code === 'E_TRANSPORT_UNAVAILABLE',
  );
});
