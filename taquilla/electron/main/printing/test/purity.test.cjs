'use strict';

const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const CORE_DIR = path.join(__dirname, '..', 'core');
const DOCUMENTS_DIR = path.join(__dirname, '..', 'documents');
const SCAN_DIRS = fs.existsSync(DOCUMENTS_DIR) ? [CORE_DIR, DOCUMENTS_DIR] : [CORE_DIR];
const FORBIDDEN_IMPORT = /(?:from\s*['"]|require\(\s*['"])(?:electron|fs|node:fs|fs\/promises|node:net|net|electron-pos-printer)['"]/;
const pureFiles = (dir) => fs.readdirSync(dir).filter((name) => name.endsWith('.ts'));

test('los módulos puros de impresión no requieren IO ni dependencia de Electron', () => {
  for (const dir of SCAN_DIRS) {
    for (const file of pureFiles(dir)) {
      const label = `${path.basename(dir)}/${file}`;
      const source = fs.readFileSync(path.join(dir, file), 'utf8');
      assert.ok(source.length > 0, `${label} no debe estar vacío`);
      assert.doesNotMatch(source, FORBIDDEN_IMPORT, `${label} no debe requerir un módulo de IO/Electron`);
      assert.equal(source.includes('electron-pos-printer'), false, `${label} no debe mencionar electron-pos-printer`);
    }
  }
});

test('format.ts no usa Intl ni toLocale', () => {
  const source = fs.readFileSync(path.join(CORE_DIR, 'format.ts'), 'utf8');
  assert.doesNotMatch(source, /Intl|toLocale/, 'format.ts no debe usar Intl ni toLocale');
});
