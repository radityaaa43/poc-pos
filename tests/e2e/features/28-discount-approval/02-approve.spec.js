import { test, expect as baseExpect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';

// Pages are slow when several agents share one machine: wait longer than the 15 s default.
const expect = baseExpect.configure({ timeout: 30_000 });

const manager = roles.find((role) => role.key === 'manager');

test.describe('28-discount-approval', () => {
    // Multi-page flows: the shared 4-CPU machine can take longer than the default 30 s.
    test.describe.configure({ timeout: 120_000 });
    test.use({ actionTimeout: 30_000 });

    test('a manager approves a pending discount and the sale becomes paid', async ({ asRole, arrange }) => {
        const { invoice } = arrange('posops.pending-discount', { cashier: 'manager@e2e.test', total: 20000, discount: 5000 });
        const page = await asRole(manager);

        await page.goto(`/dashboard/transactions/history?invoice=${invoice}`);
        await expect(page.getByRole('row').filter({ hasText: invoice })).toContainText('pending_approval');

        await page.goto('/dashboard/discount-approvals');
        const card = page.locator('div').filter({ hasText: invoice }).filter({ has: page.getByRole('button', { name: 'Setujui' }) }).last();
        await expect(card).toContainText(/Diskon:\s*Rp\s*5\.000/);
        await expect(card).toContainText(/Total:\s*Rp\s*15\.000/);

        page.once('dialog', (dialog) => {
            expect(dialog.message()).toBe(`Setujui diskon transaksi ${invoice}?`);
            return dialog.accept();
        });
        await card.getByRole('button', { name: 'Setujui' }).click();
        await expect(page.getByText(invoice)).toHaveCount(0);

        // Side effect: the sale is settled with the discount kept.
        await page.goto(`/dashboard/transactions/history?invoice=${invoice}`);
        const row = page.getByRole('row').filter({ hasText: invoice });
        await expect(row).toContainText('Lunas');
        await expect(row).toContainText(/Rp\s*15\.000/);
    });
});
