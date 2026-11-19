// Fase 7 — reanudación de sesión en /login.
//
// 1. Token vigente: /login valida GET /user y entra al dashboard sin mostrar
//    el formulario ni pedir credenciales.
// 2. Token inválido: el 401 elimina auth_token y vuelve el formulario.
//
// Ambos specs reutilizan el token del global setup (e2e/artifacts/session.json).
// No se hace POST /login: el endpoint tiene `throttle:10,2` y la suite completa
// ya consume el cupo (9 logins UI + el login API del global setup).
import { test, expect } from '@playwright/test';
import { launchApp, closeApp } from '../helpers/app.mjs';
import { loadSession } from '../helpers/api.mjs';
import { E2E_EMAIL, SELECTORS } from '../helpers/fixtures.mjs';
import { stepShot } from '../helpers/artifacts.mjs';

/** Token creado por el global setup; evita un login UI extra (throttle 10/2). */
function sessionToken() {
  const session = loadSession();
  if (!session?.token) throw new Error('Falta e2e/artifacts/session.json (global setup).');
  return session.token;
}

test.describe('login con sesion vigente', () => {
  test('token vigente reanuda la sesion sin formulario', async () => {
    const token = sessionToken();
    const { app, page } = await launchApp({ token });

    // /login valida GET /user y redirige sin esperar credenciales.
    await expect(page).toHaveURL(/\/dashboard/, { timeout: 20_000 });
    await expect(page.locator(SELECTORS.dashboard.numero)).toBeVisible({ timeout: 20_000 });
    await expect(page.locator('#login-form')).not.toBeVisible();
    await stepShot(page, '10-login-session-01-dashboard');

    const stored = await page.evaluate(() => ({
      token: localStorage.getItem('auth_token'),
      userData: localStorage.getItem('user_data'),
    }));
    expect(stored.token).toBe(token);
    expect(JSON.parse(stored.userData || 'null')?.email).toBe(E2E_EMAIL);

    await closeApp(app);
  });

  test('token invalido muestra el formulario y elimina el token', async () => {
    const { app, page } = await launchApp({ token: 'token-invalido' });

    // El 401 de /user limpia auth_token; el formulario queda utilizable.
    await expect(page.locator('#login-form')).toBeVisible({ timeout: 20_000 });
    await expect(page.locator(SELECTORS.login.email)).toBeVisible();
    await stepShot(page, '10-login-session-02-formulario');

    const token = await page.evaluate(() => localStorage.getItem('auth_token'));
    expect(token).toBeNull();

    await closeApp(app);
  });
});
