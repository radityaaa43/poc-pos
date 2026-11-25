import { test, expect as baseExpect } from '../../support/fixtures.js';
import { signIn } from '../../support/platform.js';

// Pages here are slow to answer on a shared machine: wait longer than the 15 s default.
const expect = baseExpect.configure({ timeout: 45_000 });
test.use({ actionTimeout: 60_000, navigationTimeout: 60_000 });

test.describe('notifications', () => {
    test.setTimeout(240_000);
    test('the bell lists a low-stock product until each user marks it read', async ({ page, arrange, uniq }) => {
        const title = uniq('Menipis');
        const { product_id } = arrange('platform.low-stock-product', { title });
        const first = arrange('platform.user');
        const second = arrange('platform.user');
        const bell = () => page.getByRole('button', { name: /^\d+$/ }).first();
        const item = page.getByText(`Stok habis: ${title}`);

        try {
            await signIn(page, first.email);
            await page.goto('/dashboard');
            await bell().click();
            await expect(item).toBeVisible();
            await expect(item.locator('xpath=..').getByText('Stok: 2')).toBeVisible();

            // Wait for the save: the item disappears from the list before the request is done.
            const saved = page.waitForResponse((response) => response.url().includes('/notifications/low-stock/read'));
            await item.locator('xpath=../../..').getByRole('button', { name: 'Dibaca' }).click();
            await saved;
            await expect(item).toBeHidden();

            // The read mark is the first user's own: it holds after a reload, the second user still sees it.
            await page.reload();
            await bell().click();
            await expect(item).toBeHidden();

            await page.context().clearCookies();
            await signIn(page, second.email);
            await page.goto('/dashboard');
            await bell().click();
            await expect(item).toBeVisible();
        } finally {
            arrange('platform.drop-product', { product_id });
        }
    });
});
