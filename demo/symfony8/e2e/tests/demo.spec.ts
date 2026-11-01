import { test, expect } from '@playwright/test';
import { login, loginFromOtherBrowser, openDialog, openMySessions, signOutOtherSessions } from './helpers';

/**
 * REQ-DEMO-013 — happy path of the session registry (nowo_user_kit.sessions): sign in, open "My sessions", sign out
 * the session of another browser (a second browser context), which is signed out on its next request.
 */
test.describe('User Kit demo — sessions', () => {
  test('home page shows the account status and presence after login', async ({ page }) => {
    await login(page);
    await expect(page.getByText('demo@user-kit.test').first()).toBeVisible();
    await expect(page.getByText('Online (UserPresenceResolver): yes')).toBeVisible();
  });

  test('"My sessions" lists the current session and requires login', async ({ page, browser }) => {
    const anonymous = await browser.newContext();
    const anonymousPage = await anonymous.newPage();
    await anonymousPage.goto('/account/sessions');
    await expect(anonymousPage).toHaveURL(/\/login$/);
    await anonymous.close();

    await login(page);
    await page.getByTestId('demo-nav-sessions').click();
    await expect(page.getByTestId('nowo-user-kit-sessions')).toBeVisible();
    await expect(page.getByTestId('nowo-user-kit-session-current')).toHaveCount(1);
  });

  test('sign out another session from "My sessions"', async ({ page, browser }) => {
    await login(page);
    await signOutOtherSessions(page);

    const other = await loginFromOtherBrowser(browser);

    await openMySessions(page);
    const otherRow = page.getByRole('row').filter({ hasText: 'Firefox on Linux' });
    await expect(otherRow).toHaveCount(1);
    await expect(page.getByRole('row').filter({ has: page.getByTestId('nowo-user-kit-session-current') })).not.toContainText(
      'Firefox',
    );

    await otherRow.getByTestId('nowo-user-kit-sessions-revoke').click();
    await expect(openDialog(page)).toBeVisible();
    await openDialog(page).getByRole('button', { name: 'Sign out', exact: true }).click();

    await openMySessions(page);
    await expect(page.getByRole('row').filter({ hasText: 'Firefox on Linux' })).toHaveCount(0);
    await expect(page.getByTestId('nowo-user-kit-session-current')).toHaveCount(1);

    // The revoked browser is signed out on its next request.
    await other.page.goto('/account/sessions');
    await expect(other.page).toHaveURL(/\/login$/);
    await other.context.close();
  });
});
