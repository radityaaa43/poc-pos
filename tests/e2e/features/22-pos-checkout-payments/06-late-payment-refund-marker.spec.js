import { test, expect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';

const cashier = roles.find((role) => role.key === 'cashier');

test.describe('POS checkout: late payment of an old attempt', () => {
    test.describe.configure({ timeout: 240_000 });

    test('the history marks a sale paid twice and the chip lists only those', { tag: ['@REQ-003-AC-19'] }, async ({ asRole, arrange }) => {
        arrange('pos.open-shift', { cash: 100000 });
        const late = arrange('posretry.failed-sale', { qty: 1, status: 'paid', late: 1 });
        const normal = arrange('posretry.failed-sale', { qty: 1, status: 'paid' });
        const page = await asRole(cashier);

        await page.goto('/dashboard/transactions/history');
        await expect(page.getByText(late.invoice).first()).toBeVisible();
        await expect(page.getByText('Bayar ganda — refund manual').first()).toBeVisible();

        await page.getByRole('button', { name: 'Perlu refund' }).click();

        await expect(page.getByText(late.invoice).first()).toBeVisible();
        await expect(page.getByText(normal.invoice)).toHaveCount(0);
    });

    test('the receipt tells the cashier the old payment needs a manual refund', { tag: ['@REQ-003-AC-19'] }, async ({ asRole, arrange }) => {
        arrange('pos.open-shift', { cash: 100000 });
        const sale = arrange('posretry.failed-sale', { qty: 1, status: 'paid', late: 1 });
        const page = await asRole(cashier);

        await page.goto(`/dashboard/transactions/${sale.invoice}/print`);

        await expect(page.getByText('Pembayaran lama diterima setelah dibayar ulang — perlu refund manual')).toBeVisible();
    });
});
