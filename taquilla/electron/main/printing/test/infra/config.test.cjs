'use strict';

const { test } = require('node:test');
const assert = require('node:assert/strict');
const { resolveConfig, saveConfig } = require('../../dist/infra/config.js');

const missingFile = () => {
  const error = new Error('missing');
  error.code = 'ENOENT';
  throw error;
};
const fileWith = (value) => () => (typeof value === 'string' ? value : JSON.stringify(value));
const config = (extra = {}) => resolveConfig({ userDataPath: '/tmp/lotto', readFile: missingFile, ...extra });
const writer = (initial) => {
  const writes = [];
  const renames = [];
  return {
    writes,
    renames,
    readFile: fileWith(initial),
    writeFile: (target, data, encoding) => writes.push({ target, data, encoding }),
    renameFile: (from, to) => renames.push({ from, to }),
  };
};
const save = (fakes, patch) => saveConfig({
  userDataPath: '/tmp/lotto',
  readFile: fakes.readFile,
  writeFile: fakes.writeFile,
  renameFile: fakes.renameFile,
}, patch);

test('la configuracion predeterminada es 58mm / 32 columnas / 384 puntos con nombre nulo', () => {
  const result = config();
  assert.deepEqual(
    [result.printerName, result.paper, result.columns, result.dots, result.codePage, result.cut, result.feedLines, result.cutPerBlock],
    [null, '58mm', 32, 384, 'cp850', 'partial', 3, true],
  );
  assert.equal('printTest' in result, false);
  assert.deepEqual(result.warnings, []);
});

test('los alias de papel 58, 80 y 80-512 se normalizan desde el archivo', () => {
  const paper = (value) => {
    const result = config({ readFile: fileWith({ paper: value }) });
    return [result.paper, result.columns, result.dots];
  };
  assert.deepEqual(paper('58'), ['58mm', 32, 384]);
  assert.deepEqual(paper('80'), ['80mm', 48, 576]);
  assert.deepEqual(paper('80-512'), ['80mm-512', 42, 512]);
});

test('el archivo sobrescribe los predeterminados', () => {
  const result = config({
    readFile: fileWith({
      printerName: 'Archivo', paper: '80mm', columns: 44,
      codePage: 'cp858', cut: 'full', feedLines: 5, cutPerBlock: false,
    }),
  });
  assert.deepEqual(
    [result.printerName, result.paper, result.columns, result.dots, result.codePage, result.cut, result.feedLines, result.cutPerBlock],
    ['Archivo', '80mm', 44, 576, 'cp858', 'full', 5, false],
  );
  assert.deepEqual(result.warnings, []);
});

test('un JSON malformado se ignora con una advertencia', () => {
  const result = config({ readFile: fileWith('{ no es json') });
  assert.equal(result.paper, '58mm');
  assert.equal(result.warnings.length, 1);
  assert.match(result.warnings[0], /printer-config\.json/);
});

test('valores de enum o enteros invalidos vuelven al predeterminado con advertencia', () => {
  const result = config({
    readFile: fileWith({ paper: 'A4', columns: 10, codePage: 'utf8', cut: 'x', feedLines: 99, cutPerBlock: 'si' }),
  });
  assert.deepEqual(
    [result.paper, result.columns, result.codePage, result.cut, result.feedLines, result.cutPerBlock],
    ['58mm', 32, 'cp850', 'partial', 3, true],
  );
  assert.equal(result.warnings.length, 6);
});

test('printerName queda nulo si falta, esta en blanco o no es texto, y printTest ya no existe', () => {
  assert.equal(config({ readFile: fileWith({ paper: '58mm' }) }).printerName, null);
  assert.equal(config({ readFile: fileWith({ printerName: '   ' }) }).printerName, null);
  assert.equal(config({ readFile: fileWith({ printerName: null }) }).printerName, null);
  const nonText = config({ readFile: fileWith({ printerName: 42 }) });
  assert.equal(nonText.printerName, null);
  assert.equal(nonText.warnings.length, 1);
  assert.equal('printTest' in nonText, false);
});

test('saveConfig escribe el objeto canonico con temp + rename y el resolve posterior lo devuelve igual', () => {
  const fakes = writer({});
  const result = save(fakes, { printerName: 'POS-58', paper: '80mm', codePage: 'cp858', cut: 'full', feedLines: 5, cutPerBlock: false });
  assert.equal(fakes.writes.length, 1);
  assert.equal(fakes.writes[0].target, '/tmp/lotto/printer-config.json.tmp');
  assert.equal(fakes.writes[0].encoding, 'utf8');
  assert.deepEqual(fakes.renames, [{ from: '/tmp/lotto/printer-config.json.tmp', to: '/tmp/lotto/printer-config.json' }]);
  assert.deepEqual(JSON.parse(fakes.writes[0].data), {
    version: 1, printerName: 'POS-58', paper: '80mm', codePage: 'cp858', cut: 'full', feedLines: 5, cutPerBlock: false,
  });
  assert.ok(fakes.writes[0].data.endsWith('\n'));
  assert.deepEqual(
    [result.printerName, result.paper, result.columns, result.dots, result.codePage, result.cut, result.feedLines, result.cutPerBlock, result.warnings],
    ['POS-58', '80mm', 48, 576, 'cp858', 'full', 5, false, []],
  );
});

test('saveConfig conserva los campos no enviados y permite quitar el override de columnas', () => {
  const current = { version: 1, printerName: 'Vieja', paper: '80mm', columns: 44, codePage: 'cp858', cut: 'none', feedLines: 7, cutPerBlock: false };
  const kept = writer(current);
  const result = save(kept, { printerName: 'Nueva' });
  assert.deepEqual(JSON.parse(kept.writes[0].data), { ...current, printerName: 'Nueva' });
  assert.deepEqual(
    [result.paper, result.columns, result.codePage, result.cut, result.feedLines, result.cutPerBlock],
    ['80mm', 44, 'cp858', 'none', 7, false],
  );

  const cleared = writer(current);
  const clearedResult = save(cleared, { printerName: 'Nueva', columns: null });
  assert.equal('columns' in JSON.parse(cleared.writes[0].data), false);
  assert.equal(clearedResult.columns, 48);
});

test('saveConfig rechaza valores invalidos y nombres con controles, y guarda null si el nombre esta vacio', () => {
  const invalid = [
    { printerName: 'POS', paper: '58' }, { printerName: 'POS', columns: 10 },
    { printerName: 'POS', codePage: 'utf8' }, { printerName: 'POS', cut: 'x' },
    { printerName: 'POS', feedLines: 11 }, { printerName: 'POS', cutPerBlock: 'si' },
    { printerName: 'POS\nX' }, { printerName: 'POS\u0007' }, { printerName: 'A'.repeat(129) }, {},
  ];
  for (const patch of invalid) {
    const fakes = writer({});
    assert.throws(() => save(fakes, patch), (error) => error.code === 'E_CONFIG_INVALID', JSON.stringify(patch));
    assert.equal(fakes.writes.length, 0);
  }
  const fakes = writer({});
  const result = save(fakes, { printerName: '   ' });
  assert.equal(result.printerName, null);
  assert.equal(JSON.parse(fakes.writes[0].data).printerName, null);
});

test('saveConfig convierte fallos de escritura o rename en E_CONFIG_WRITE_FAILED', () => {
  const writeFail = { readFile: fileWith({}), writeFile: () => { throw new Error('sin permiso'); }, renameFile: () => {} };
  assert.throws(
    () => save(writeFail, { printerName: 'POS' }),
    (error) => error.code === 'E_CONFIG_WRITE_FAILED' && error.details.cause === 'sin permiso',
  );
  const fakes = writer({});
  const renameFail = { ...fakes, renameFile: () => { throw new Error('rename roto'); } };
  assert.throws(() => save(renameFail, { printerName: 'POS' }), (error) => error.code === 'E_CONFIG_WRITE_FAILED');
  assert.throws(() => saveConfig({}, { printerName: 'POS' }), (error) => error.code === 'E_CONFIG_WRITE_FAILED');
});

test('saveConfig repara un archivo malformado y descarta claves desconocidas', () => {
  const malformed = writer('{ roto');
  const result = save(malformed, { printerName: 'POS-58' });
  assert.deepEqual(JSON.parse(malformed.writes[0].data), {
    version: 1, printerName: 'POS-58', paper: '58mm', codePage: 'cp850', cut: 'partial', feedLines: 3, cutPerBlock: true,
  });
  assert.deepEqual(result.warnings, []);

  const extras = writer({ printerName: 'POS-58', paper: '58mm', foo: 'bar', version: 9 });
  save(extras, { printerName: 'POS-58' });
  assert.equal('foo' in JSON.parse(extras.writes[0].data), false);
  assert.equal(JSON.parse(extras.writes[0].data).version, 1);
});
