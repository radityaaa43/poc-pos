import { test, expect } from '../../support/fixtures.js';
import { login } from '../../support/login.js';
import { roles } from '../../support/roles.js';

// Each role's real login (and its landing page) is exercised once by support/auth.setup.js;
// here a saved session must keep the role signed in on that page.
test.describe('smoke: login', () => {
    for (const role of roles) {
        test(`${role.key} stays signed in on ${role.landing}`, async ({ asRole }) => {
            const page = await asRole(role);

            await page.goto(role.landing);
            await expect(page).toHaveURL(new RegExp(`${role.landing}$`));
        });
    }

    test('a guest is sent to the login page', async ({ page }) => {
        await page.goto('/dashboard');
        await expect(page).toHaveURL(/\/login$/);
    });

    test('a wrong password is refused', async ({ page }) => {
        await login(page, 'nobody@e2e.test', 'not-the-password');
        await expect(page).toHaveURL(/\/login$/);
        await expect(page.getByText('These credentials do not match our records.')).toBeVisible();
    });

    test('logout returns to the login page', async ({ page }) => {
        // Own login: logging out kills the session, which the saved role sessions must not share.
        await login(page, 'cashier@e2e.test');
        await expect(page).toHaveURL(/\/dashboard\/transactions$/);

        await page.getByRole('button', { name: 'Logout' }).click();
        await expect(page).not.toHaveURL(/\/dashboard/);
        await page.goto('/dashboard/transactions');
        await expect(page).toHaveURL(/\/login$/);
    });
});
