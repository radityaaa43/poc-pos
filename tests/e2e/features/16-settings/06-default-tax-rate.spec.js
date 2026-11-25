import { test, expect as baseExpect } from '../../support/fixtures.js';
import { signIn } from '../../support/platform.js';

// Pages here are slow to answer on a shared machine: wait longer than the 15 s default.
const expect = baseExpect.configure({ timeout: 45_000 });
test.use({ actionTimeout: 60_000, navigationTimeout: 60_000 });

test.describe('default tax rate', () => {
    test.setTimeout(240_000);

    test('a new product starts with the default PPN rate of the store profile', async ({ page, arrange }) => {
        const { email } = arrange('platform.user');
        const rate = page.getByRole('spinbutton');
        const save = async () => {
            const saved = page.waitForResponse((response) => response.url().endsWith('/settings/store') && response.request().method() === 'POST');
            await page.getByRole('button', { name: 'Simpan Profil' }).click();
            await saved;
        };

        await signIn(page, email);
        await page.goto('/dashboard/settings/store');
        const original = await rate.inputValue();

        try {
            await rate.fill('12.5');
            await save();
            await page.goto('/dashboard/products/create');
            await expect(page.getByPlaceholder('11', { exact: true })).toHaveValue('12.5');
        } finally {
            await page.goto('/dashboard/settings/store');
            await rate.fill(original);
            await save();
        }
    });
});
