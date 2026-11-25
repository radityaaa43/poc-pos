import { test, expect as baseExpect } from '../../support/fixtures.js';
import { signIn } from '../../support/platform.js';

// Pages here are slow to answer on a shared machine: wait longer than the 15 s default.
const expect = baseExpect.configure({ timeout: 45_000 });
test.use({ actionTimeout: 60_000, navigationTimeout: 60_000 });

test.describe('sales target', () => {
    test.setTimeout(240_000);

    test('the monthly target saves, shows again, and drives the dashboard progress card', async ({ page, arrange }) => {
        const { email } = arrange('platform.user');
        const target = page.getByPlaceholder('Contoh: 50000000');
        const save = async () => {
            const saved = page.waitForResponse((response) => response.url().endsWith('/settings/target') && response.request().method() === 'POST');
            await target.press('Enter');
            await saved;
        };

        await signIn(page, email);
        await page.goto('/dashboard/settings/target');
        const original = await target.inputValue();

        try {
            // A negative target is refused.
            await target.fill('-5');
            await save();
            await expect(page.getByText('The monthly sales target field must be at least 0.')).toBeVisible();

            await target.fill('12345678');
            await expect(page.getByText(/Target:\s*Rp\s*12\.345\.678/)).toBeVisible();
            await save();
            await page.reload();
            await expect(target).toHaveValue('12345678');

            await page.goto('/dashboard');
            await expect(page.getByText(/Rp\s*0\s*\/\s*Rp\s*12\.345\.678/)).toBeVisible();
        } finally {
            await page.goto('/dashboard/settings/target');
            await target.fill(original);
            await save();
        }
    });
});
