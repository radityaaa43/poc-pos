import { test, expect } from '../../support/fixtures.js';
import { signIn } from '../../support/platform.js';

test.describe('32-kitchen-display', () => {
    test.describe.configure({ timeout: 240_000 });

    test('a new order shows within 10 seconds, and the screen can switch to English', { tag: ['@REQ-003-AC-24'] }, async ({ page, arrange, uniq }) => {
        // Own user: choosing a language is saved on the account, so a shared role must not take it.
        const { email } = arrange('dine.user', { email: `${uniq('dapur')}@e2e.test`, permissions: 'dashboard-access,dine-orders-prepare' });
        await signIn(page, email);
        await page.goto('/dashboard/kitchen');
        await expect(page.getByRole('main').getByRole('heading', { name: 'Layar Dapur', exact: true })).toBeVisible();

        // Sold after the screen is open: it must arrive by itself.
        const sale = arrange('kitchen-sale', { prefix: uniq('Cepat') });
        await expect(page.getByRole('heading', { name: sale.invoice, exact: true })).toBeVisible({ timeout: 10_000 });

        await page.getByTitle('Change Language').click();
        await page.getByRole('menuitem', { name: /English/ }).click();
        await expect(page.getByRole('main').getByRole('heading', { name: 'Kitchen display', exact: true })).toBeVisible();
        await expect(page.locator('article', { hasText: sale.invoice })).toContainText('In store');
    });
});
