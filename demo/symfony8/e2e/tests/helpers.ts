import { expect, type Browser, type BrowserContext, type Page } from '@playwright/test';

/** Demo credentials (created on first visit by DemoController). */
export const DEMO_EMAIL = 'demo@user-kit.test';
export const DEMO_PASSWORD = 'demo';

/** User agent of the "other browser": the sessions list shows it as "Firefox on Linux". */
export const OTHER_BROWSER_UA = 'Mozilla/5.0 (X11; Linux x86_64; rv:140.0) Gecko/20100101 Firefox/140.0';

/** Sign in through the demo login form and land on the home page. */
export async function login(page: Page): Promise<void> {
  await page.goto('/login');
  await page.getByLabel('Email').fill(DEMO_EMAIL);
  await page.getByLabel('Password').fill(DEMO_PASSWORD);
  await page.getByRole('button', { name: 'Sign in' }).click();
  await expect(page.getByTestId('demo-sessions-link')).toBeVisible();
}

/** A second, isolated browser context (own cookies = a second session) signed in as the demo user. */
export async function loginFromOtherBrowser(browser: Browser): Promise<{ context: BrowserContext; page: Page }> {
  const context = await browser.newContext({ userAgent: OTHER_BROWSER_UA });
  const page = await context.newPage();
  await login(page);
  return { context, page };
}

/** Open "My sessions" and wait for the UiKit scripts (confirm dialogs) to be ready. */
export async function openMySessions(page: Page): Promise<void> {
  await page.goto('/account/sessions');
  await expect(page.getByTestId('nowo-user-kit-sessions')).toBeVisible();
  await page.waitForFunction(() => typeof (window as unknown as { nowoUiOpenConfirm?: unknown }).nowoUiOpenConfirm === 'function');
}

/** The open UiKit confirm dialog. */
export function openDialog(page: Page) {
  return page.locator('dialog[open]');
}

/**
 * Leave only the current session: earlier runs (or a retry) may have left sessions behind. Uses the header action
 * "Sign out all other sessions" when there is more than one row.
 */
export async function signOutOtherSessions(page: Page): Promise<void> {
  await openMySessions(page);
  if ((await page.getByTestId('nowo-user-kit-session-client').count()) > 1) {
    await page.getByTestId('nowo-user-kit-sessions-revoke-others').click();
    await openDialog(page).getByRole('button', { name: 'Sign out all other sessions' }).click();
    await openMySessions(page);
  }
  await expect(page.getByTestId('nowo-user-kit-session-client')).toHaveCount(1);
}

/** Wait until the Symfony Web Debug Toolbar has finished loading (optional outside dev). */
export async function waitForWebProfiler(page: Page): Promise<void> {
  await page.waitForSelector('.sf-toolbar', { state: 'visible', timeout: 20000 }).catch(() => undefined);
  const mini = page.locator('.sf-minitoolbar a').first();
  if (await mini.isVisible().catch(() => false)) {
    await mini.click();
  }
  await page
    .waitForFunction(
      () => {
        const bar = document.querySelector('.sf-toolbar') as HTMLElement | null;
        if (!bar || bar.offsetParent === null) {
          return true;
        }
        return !!document.querySelector('.sf-toolbar-block');
      },
      { timeout: 25000, polling: 250 },
    )
    .catch(() => undefined);
  await new Promise((r) => setTimeout(r, 400));
}
