import { test, expect } from '@playwright/test';
import { mkdirSync } from 'node:fs';
import { resolve } from 'node:path';
import { login, loginFromOtherBrowser, openDialog, openMySessions, signOutOtherSessions, waitForWebProfiler } from './helpers';

/** REQ-DEMO-013 — README screenshots (captured as PNG, converted to WebP by scripts/to-webp.mjs). */
const outDir = process.env.SCREENSHOT_DIR
  ? resolve(process.env.SCREENSHOT_DIR)
  : resolve(__dirname, '../../../../docs/images/demo');

test.beforeAll(() => {
  mkdirSync(outDir, { recursive: true });
});

test.describe('User Kit screenshots', () => {
  test.use({ viewport: { width: 1280, height: 640 } });

  test('my-sessions and sign-out-session — two browsers signed in, then the sign-out confirmation', async ({
    page,
    browser,
  }) => {
    await login(page);
    await signOutOtherSessions(page);
    const other = await loginFromOtherBrowser(browser);

    await openMySessions(page);
    await expect(page.getByRole('row').filter({ hasText: 'Firefox on Linux' })).toHaveCount(1);
    await waitForWebProfiler(page);
    await page.screenshot({ path: resolve(outDir, 'my-sessions.png') });

    await page.getByRole('row').filter({ hasText: 'Firefox on Linux' }).getByTestId('nowo-user-kit-sessions-revoke').click();
    await expect(openDialog(page)).toBeVisible();
    await page.screenshot({ path: resolve(outDir, 'sign-out-session.png') });

    await other.context.close();
  });
});
