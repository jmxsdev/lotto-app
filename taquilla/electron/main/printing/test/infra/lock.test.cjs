'use strict';

const { test } = require('node:test');
const assert = require('node:assert/strict');
const { createLock } = require('../../dist/infra/lock.js');

const deferred = () => {
  let resolve;
  const promise = new Promise((res) => { resolve = res; });
  return { promise, resolve };
};

test('un segundo run mientras el primero esta en vuelo rechaza E_PRINT_BUSY', async () => {
  const lock = createLock();
  const gate = deferred();
  const first = lock.run(() => gate.promise);
  await assert.rejects(
    lock.run(async () => 'segundo'),
    (error) => error.code === 'E_PRINT_BUSY' && /impresion en curso/.test(error.message),
  );
  assert.equal(lock.isBusy(), true);
  gate.resolve('primero');
  assert.equal(await first, 'primero');
  assert.equal(lock.isBusy(), false);
});

test('despues de que el primero termina con exito, el siguiente run se permite', async () => {
  const lock = createLock();
  assert.equal(await lock.run(async () => 'uno'), 'uno');
  assert.equal(await lock.run(async () => 'dos'), 'dos');
});

test('despues de que el primero falla, el siguiente run se permite y isBusy queda en false', async () => {
  const lock = createLock();
  await assert.rejects(lock.run(async () => { throw new Error('fallo'); }), /fallo/);
  assert.equal(lock.isBusy(), false);
  assert.equal(await lock.run(async () => 'recuperado'), 'recuperado');
});

test('una tarea que no es funcion rechaza E_PRINT_LOCK_INVALID', async () => {
  const lock = createLock();
  await assert.rejects(lock.run(null), (error) => error.code === 'E_PRINT_LOCK_INVALID');
  await assert.rejects(lock.run('no es funcion'), (error) => error.code === 'E_PRINT_LOCK_INVALID');
});
