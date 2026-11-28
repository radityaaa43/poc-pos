import { test, expect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';

const cashier = roles.find((role) => role.key === 'cashier');

test.describe('POS checkout: a credit sale is not retried', () => {
    test.describe.configure({ timeout: 240_000 });

    test('the receipt of a failed credit sale offers no retry', async ({ asRole, arrange }) => {
        arrange('pos.open-shift', { cash: 100000 });
        const sale = arrange('posops.receivable', { total: 100000 });
        arrange('kitchen.set-status', { invoice: sale.invoice, status: 'failed' });
        const page = await asRole(cashier);

        await page.goto(`/dashboard/transactions/${sale.invoice}/print`);

        await expect(page.getByText(sale.invoice).first()).toBeVisible();
        await expect(page.getByRole('button', { name: 'Coba lagi' })).toHaveCount(0);
        await expect(page.getByRole('button', { name: 'Bayar dengan cara lain' })).toHaveCount(0);
    });
});
