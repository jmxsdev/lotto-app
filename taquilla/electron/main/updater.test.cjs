// Test puro de resolveFeedUrl (TQ-10, U2). Runner: node --test.
//   node --test taquilla/electron/main/updater.test.cjs
// No requiere Electron ni electron-updater instalado.

'use strict';

const { test } = require('node:test');
const assert = require('node:assert/strict');

const { resolveFeedUrl, FEED_PATH } = require('./updater.cjs');

test('prod sin barra final', () => {
  assert.equal(resolveFeedUrl('https://lotto.gzuz.dev'), 'https://lotto.gzuz.dev' + FEED_PATH);
});

test('prod con barra final y doble barra', () => {
  assert.equal(resolveFeedUrl('https://lotto.gzuz.dev/'), 'https://lotto.gzuz.dev' + FEED_PATH);
  assert.equal(resolveFeedUrl('https://lotto.gzuz.dev//'), 'https://lotto.gzuz.dev' + FEED_PATH);
});

test('dev upstream local', () => {
  assert.equal(resolveFeedUrl('http://localhost:8000'), 'http://localhost:8000' + FEED_PATH);
});

test('upstream vacio/null/undefined → null', () => {
  assert.equal(resolveFeedUrl(''), null);
  assert.equal(resolveFeedUrl(null), null);
  assert.equal(resolveFeedUrl(undefined), null);
  assert.equal(resolveFeedUrl('   '), null);
});

test('espacios alrededor se recortan', () => {
  assert.equal(resolveFeedUrl('  https://lotto.gzuz.dev  '), 'https://lotto.gzuz.dev' + FEED_PATH);
});