import { test, expect as baseExpect } from '../../support/fixtures.js';
import { signIn } from '../../support/platform.js';
import { PASSWORD } from '../../support/roles.js';

// Pages here are slow to answer on a shared machine: wait longer than the 15 s default.
const expect = baseExpect.configure({ timeout: 45_000 });
test.use({ actionTimeout: 60_000, navigationTimeout: 60_000 });

test.describe('language switch', () => {
    test.setTimeout(240_000);

    test('the header offers Indonesian and English', async ({ page, arrange }) => {
        const { email } = arrange('platform.user');
        await signIn(page, email);
        await page.goto('/dashboard');

        const switcher = page.getByTitle('Change Language');
        await expect(switcher).toContainText('ID');
        await switcher.click();
        await expect(page.getByRole('menuitem', { name: /Indonesia/ })).toBeVisible();
        await expect(page.getByRole('menuitem', { name: /English/ })).toBeVisible();
    });

    test('choosing English switches the page and is remembered for the user', async ({ page, arrange }) => {
        const { email } = arrange('platform.user');
        await signIn(page, email);
        await page.goto('/dashboard');

        await page.getByTitle('Change Language').click();
        await page.getByRole('menuitem', { name: /English/ }).click();
        await expect(page.getByTitle('Change Language')).toContainText('EN');
        await expect(page.getByRole('button', { name: 'Guided Tour' })).toBeVisible();

        // Signing in again keeps English. The page text follows the browser's localStorage (the server stores the
        // choice on the user for server-side messages), so the login form is English too: signIn() expects Indonesian.
        await page.context().clearCookies();
        await page.goto('/login');
        await page.getByPlaceholder('name@email.com').fill(email);
        await page.locator('input[name=password]').fill(PASSWORD);
        const signedIn = page.waitForResponse((response) => response.url().endsWith('/login') && response.request().method() === 'POST');
        await page.getByRole('button', { name: 'Sign In' }).click();
        await signedIn;
        await page.goto('/dashboard');
        await expect(page.getByTitle('Change Language')).toContainText('EN');
    });
});
