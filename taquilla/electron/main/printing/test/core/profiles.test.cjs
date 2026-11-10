'use strict';

const { test } = require('node:test');
const assert = require('node:assert/strict');
const { resolveProfile } = require('../../dist/core/profiles.js');

test('el perfil predeterminado es 58mm / 32 columnas / 384 puntos; 80mm y 80mm-512 son opcionales', () => {
  assert.deepEqual(resolveProfile(), { paper: '58mm', columns: 32, dots: 384, warnings: [] });
  assert.deepEqual(resolveProfile({ paper: '80mm' }), { paper: '80mm', columns: 48, dots: 576, warnings: [] });
  assert.deepEqual(resolveProfile({ paper: '80mm-512' }), { paper: '80mm-512', columns: 42, dots: 512, warnings: [] });
});

test('la anulación de columnas acepta 20..80; valores y papel inválidos vuelven al valor predeterminado con una advertencia', () => {
  assert.equal(resolveProfile({ columns: 20 }).columns, 20);
  assert.equal(resolveProfile({ columns: 80 }).columns, 80);
  for (const input of [{ columns: 19 }, { columns: 81 }, { paper: 'nope' }]) {
    const out = resolveProfile(input);
    assert.equal(out.paper, '58mm');
    assert.equal(out.columns, 32);
    assert.equal(out.warnings.length, 1);
  }
});
