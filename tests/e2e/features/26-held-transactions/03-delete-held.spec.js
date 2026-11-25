import { test, expect as baseExpect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';

// Pages are slow when several agents share one machine: wait longer than the 15 s default.
const expect = baseExpect.configure({ timeout: 30_000 });

const cashier = roles.find((role) => role.key === 'cashier');

test.describe('26-held-transactions', () => {
    // Multi-page flows: the shared 4-CPU machine can take longer than the default 30 s.
    test.describe.configure({ timeout: 120_000 });
    test.use({ actionTimeout: 30_000 });

    test('deleting a held transaction asks first and then removes it', async ({ asRole, arrange, uniq }) => {
        arrange('open-shift', { cash: 100000 });
        const { label } = arrange('posops.held-cart', { label: uniq('Meja') });
        const page = await asRole(cashier);

        await page.goto('/dashboard/transactions');
        await page.getByRole('button', { name: /Transaksi Ditahan/ }).click();
        // The row's second button is the trash icon (no accessible name).
        const deleteButton = page.getByText(label, { exact: true }).locator('xpath=../..').getByRole('button').nth(1);

        page.once('dialog', (dialog) => dialog.dismiss());
        await deleteButton.click();
        await expect(page.getByText(label, { exact: true })).toBeVisible();

        page.once('dialog', (dialog) => {
            expect(dialog.message()).toBe('Hapus transaksi yang ditahan ini?');
            return dialog.accept();
        });
        await deleteButton.click();
        await expect(page.getByRole('button', { name: /Transaksi Ditahan/ })).toHaveCount(0);
        await expect(page.getByText(label, { exact: true })).toHaveCount(0);
    });
});
