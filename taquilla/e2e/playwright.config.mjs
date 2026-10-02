import { defineConfig } from '@playwright/test';

// Configuración del harness E2E de taquilla (design: Architecture Decisions #2).
// - workers=1: una sola instancia Electron a la vez (estado fresco por spec).
// - retries CI?2:0: en CI tolera flakiness de Electron; local corre directo.
// - reporter list+html; outputDir e2e/artifacts/ (gitignored).
// - globalSetup: guard local → healthcheck → build dist → login API.
export default defineConfig({
  testDir: './tests',
  // Solo specs de Playwright; guard.test.mjs es un test de `node --test`.
  testMatch: '**/*.spec.mjs',
  workers: 1,
  retries: process.env.CI ? 2 : 0,
  timeout: 90_000,
  expect: { timeout: 15_000 },
  reporter: [
    ['list'],
    // Fuera de outputDir: el HTML se regenera y limpiaría los artefactos.
    ['html', { outputFolder: 'html-report', open: 'never' }],
  ],
  outputDir: 'artifacts/',
  globalSetup: './global-setup.mjs',
  globalTeardown: './global-teardown.mjs',
  use: {
    // E2E_TRACE=1 → trace siempre; default on-first-retry (CI).
    trace: process.env.E2E_TRACE === '1' ? 'on' : 'on-first-retry',
  },
});