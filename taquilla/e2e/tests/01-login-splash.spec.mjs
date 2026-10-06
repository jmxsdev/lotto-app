// Login/Splash (REQ-3): boot → splash verifica dispositivo (fingerprint
// inyectado) → /login → credenciales E2E → /dashboard.
// El reloj está congelado (clock.install ≡ pauseAt): el redirect post-login
// (setTimeout 1s) solo dispara con advanceClock (fastForward).
import { test, expect } from '@playwright/test';
import { launchApp, closeApp, advanceClock } from '../helpers/app.mjs';
import { E2E_EMAIL, E2E_PASSWORD, SELECTORS } from '../helpers/fixtures.mjs';
import { stepShot } from '../helpers/artifacts.mjs';

test.describe('login/splash', () => {
  test('boot → splash → login → dashboard', async () => {
    const { app, page } = await launchApp();

    // 1–2. El splash ya verificó el dispositivo (fingerprint inyectado →
    //    status active) y redirigió a /login en app:// (launchApp asienta).
    await expect(page).toHaveURL(/^app:\/\//, { timeout: 20_000 });
    await expect(page).toHaveURL(/\/login/, { timeout: 15_000 });
    await stepShot(page, 'login-01-form');

    // 3. Credenciales del usuario E2E (role taquilla, taquilla activa).
    await page.fill(SELECTORS.login.email, E2E_EMAIL);
    await page.fill(SELECTORS.login.password, E2E_PASSWORD);
    await page.click(SELECTORS.login.submit);

    // 4. La API responde → token en localStorage; el redirect a /dashboard
    //    es un setTimeout(1s) y el reloj está congelado → advanceClock lo dispara.
    await page.waitForFunction(() => Boolean(localStorage.getItem('auth_token')), null, {
      timeout: 15_000,
    });
    await advanceClock(page, 1_500);

    // 5. Dashboard: catálogo vía API (auth + MAC/fp → VerifyMac OK).
    await expect(page).toHaveURL(/\/dashboard/, { timeout: 20_000 });
    await expect(page.locator(SELECTORS.dashboard.numero)).toBeVisible({ timeout: 20_000 });
    await stepShot(page, 'login-02-dashboard');

    await closeApp(app);
  });
});