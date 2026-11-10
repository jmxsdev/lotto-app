'use strict';

const { test } = require('node:test');
const assert = require('node:assert/strict');
const { resolveConfig } = require('../../dist/infra/config.js');

const missingFile = () => {
  const error = new Error('missing');
  error.code = 'ENOENT';
  throw error;
};
const fileWith = (value) => () => (typeof value === 'string' ? value : JSON.stringify(value));
const base = (extra = {}) => resolveConfig({ env: {}, userDataPath: '/tmp/lotto', readFile: missingFile, ...extra });

test('la configuracion predeterminada es 58mm / 32 columnas / 384 puntos con nombre nulo', () => {
  const config = base();
  assert.equal(config.paper, '58mm');
  assert.equal(config.columns, 32);
  assert.equal(config.dots, 384);
  assert.equal(config.codePage, 'cp850');
  assert.equal(config.cut, 'partial');
  assert.equal(config.feedLines, 3);
  assert.equal(config.cutPerBlock, true);
  assert.equal('mode' in config, false);
  assert.equal(config.printTest, false);
  assert.equal(config.printerName, null);
  assert.deepEqual(config.warnings, []);
});

test('LOTTO_PRINT_PAPER mapea 58, 80 y 80-512 a columnas y puntos', () => {
  const paper = (value) => {
    const config = base({ env: { LOTTO_PRINT_PAPER: value } });
    return [config.paper, config.columns, config.dots];
  };
  assert.deepEqual(paper('58'), ['58mm', 32, 384]);
  assert.deepEqual(paper('80'), ['80mm', 48, 576]);
  assert.deepEqual(paper('80-512'), ['80mm-512', 42, 512]);
});

test('la precedencia es env > archivo > predeterminado', () => {
  const file = {
    printerName: 'Archivo', paper: '80mm', columns: 44,
    codePage: 'cp858', cut: 'full', feedLines: 5, cutPerBlock: false,
  };
  const fromFile = base({ readFile: fileWith(file) });
  assert.deepEqual(
    [fromFile.printerName, fromFile.paper, fromFile.columns, fromFile.dots, fromFile.codePage, fromFile.cut, fromFile.feedLines, fromFile.cutPerBlock],
    ['Archivo', '80mm', 44, 576, 'cp858', 'full', 5, false],
  );

  const fromEnv = base({
    readFile: fileWith(file),
    env: {
      LOTTO_PRINTER_NAME: 'Entorno', LOTTO_PRINT_PAPER: '58',
      LOTTO_PRINT_COLUMNS: '36', LOTTO_PRINT_CODEPAGE: 'ascii', LOTTO_PRINT_CUT: 'none',
      LOTTO_PRINT_FEED: '0', LOTTO_PRINT_CUT_PER_BLOCK: '1',
    },
  });
  assert.deepEqual(
    [fromEnv.printerName, fromEnv.paper, fromEnv.columns, fromEnv.dots, fromEnv.codePage, fromEnv.cut, fromEnv.feedLines, fromEnv.cutPerBlock],
    ['Entorno', '58mm', 36, 384, 'ascii', 'none', 0, true],
  );
  assert.deepEqual(fromEnv.warnings, []);
});

test('un JSON malformado se ignora con una advertencia', () => {
  const config = base({ readFile: fileWith('{ no es json') });
  assert.equal(config.paper, '58mm');
  assert.equal(config.warnings.length, 1);
  assert.match(config.warnings[0], /printer-config\.json/);
});

test('valores de enum o enteros invalidos vuelven al predeterminado con advertencia', () => {
  const config = base({
    readFile: fileWith({ paper: 'A4', columns: 10, codePage: 'utf8', cut: 'x', feedLines: 99, cutPerBlock: 'si' }),
  });
  assert.deepEqual(
    [config.paper, config.columns, config.codePage, config.cut, config.feedLines, config.cutPerBlock],
    ['58mm', 32, 'cp850', 'partial', 3, true],
  );
  assert.equal('mode' in config, false);
  assert.equal(config.warnings.length, 6);
});

test('printerName queda nulo sin configurar y LOTTO_PRINT_TEST se resuelve', () => {
  assert.equal(base({ readFile: fileWith({ paper: '58mm' }) }).printerName, null);
  assert.equal(base({ env: { LOTTO_PRINT_TEST: '1' } }).printTest, true);
  assert.equal(base({ env: { LOTTO_PRINT_TEST: '0' } }).printTest, false);
});
