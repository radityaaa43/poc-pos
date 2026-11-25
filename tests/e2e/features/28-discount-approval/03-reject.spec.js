import { test, expect as baseExpect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';

// Pages are slow when several agents share one machine: wait longer than the 15 s default.
const expect = baseExpect.configure({ timeout: 30_000 });

const manager = roles.find((role) => role.key === 'manager');

test.describe('28-discount-approval', () => {
    // Multi-page flows: the shared 4-CPU machine can take longer than the default 30 s.
    test.describe.configure({ timeout: 120_000 });
    test.use({ actionTimeout: 30_000 });

    test('a manager rejects a pending discount: it is removed and the full price is due', async ({ asRole, arrange }) => {
        const { invoice } = arrange('posops.pending-discount', { cashier: 'manager@e2e.test', total: 20000, discount: 5000 });
        const page = await asRole(manager);

        await page.goto('/dashboard/discount-approvals');
        const card = page.locator('div').filter({ hasText: invoice }).filter({ has: page.getByRole('button', { name: 'Tolak' }) }).last();

        // Dismissing the confirmation keeps the request pending.
        page.once('dialog', (dialog) => dialog.dismiss());
        await card.getByRole('button', { name: 'Tolak' }).click();
        await expect(card).toBeVisible();

        page.once('dialog', (dialog) => {
            expect(dialog.message()).toBe(`Tolak diskon transaksi ${invoice}?`);
            return dialog.accept();
        });
        await card.getByRole('button', { name: 'Tolak' }).click();
        await expect(page.getByText(invoice)).toHaveCount(0);

        // The discount is gone: the sale is back to its full Rp 20.000 and still unpaid.
        await page.goto(`/dashboard/transactions/history?invoice=${invoice}`);
        const row = page.getByRole('row').filter({ hasText: invoice });
        await expect(row).toContainText('unpaid');
        await expect(row).toContainText(/Rp\s*20\.000/);
    });
});
