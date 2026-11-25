import { test, expect as baseExpect } from '../../support/fixtures.js';
import { signIn } from '../../support/platform.js';

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

    // Bug #99: the form body carries the current language and wins over the ?locale= query, so nothing changes.
    test.fixme('choosing English switches the page and is remembered for the user', async ({ page, arrange }) => {
        const { email } = arrange('platform.user');
        await signIn(page, email);
        await page.goto('/dashboard');

        await page.getByTitle('Change Language').click();
        await page.getByRole('menuitem', { name: /English/ }).click();
        await expect(page.getByTitle('Change Language')).toContainText('EN');
        await expect(page.getByRole('button', { name: 'User Guide' })).toBeVisible();

        // Signing in again keeps English (the choice is stored on the user).
        await page.context().clearCookies();
        await signIn(page, email);
        await expect(page.getByTitle('Change Language')).toContainText('EN');
    });
});
