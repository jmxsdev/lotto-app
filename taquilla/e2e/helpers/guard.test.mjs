import { test } from 'node:test';
import assert from 'node:assert/strict';

// RED (task 1.1): el módulo guard.mjs aún no existe → el test debe fallar.
// GREEN (task 1.2): assertLocalApi() rechaza cualquier upstream no local.
import { assertLocalApi } from './guard.mjs';

test('assertLocalApi acepta upstreams locales http', () => {
  const a = assertLocalApi('http://localhost:8000');
  assert.equal(a.hostname, 'localhost');
  assert.equal(a.port, '8000');

  const b = assertLocalApi('http://localhost:8003');
  assert.equal(b.port, '8003');

  const c = assertLocalApi('http://127.0.0.1:8000');
  assert.equal(c.hostname, '127.0.0.1');
});

test('assertLocalApi rechaza cualquier upstream no-local (prod, LAN, https, vacío)', () => {
  const invalidos = [
    'https://lotto.gzuz.dev',
    'https://lotto.gzuz.dev/api/v1',
    'http://lotto.gzuz.dev',
    'http://192.168.1.5:8000',
    'http://10.0.0.1:8000',
    'http://example.com',
    'https://localhost:8000',
    '',
    undefined,
  ];
  for (const value of invalidos) {
    assert.throws(
      () => assertLocalApi(value),
      /local/i,
      `debería rechazar: ${String(value)}`
    );
  }
});

test('assertLocalApi rechaza strings que no son URLs', () => {
  assert.throws(() => assertLocalApi('no-es-una-url'), /URL|local/i);
  assert.throws(() => assertLocalApi('localhost:8000'), /URL|local/i);
});