import { test, expect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';
import { applyFilters, resetFilters, expectCard, rp, saleRow } from '../../support/reports.js';

const manager = roles.find((role) => role.key === 'manager');

test.describe('sales report: customer and cashier filters', () => {
    test('narrow by customer or cashier, then reset', async ({ asRole, arrange }) => {
        test.slow(); // several filter round trips
        const { prefix, a1, a2, customer } = arrange('reports.sales');
        const page = await asRole(manager);

        await page.goto('/dashboard/reports/sales');
        await applyFilters(page, { invoice: prefix });
        await expect(saleRow(page, a1)).toBeVisible();
        await expect(saleRow(page, a2)).toBeVisible();

        // Only A2 belongs to the customer.
        await applyFilters(page, { option: { placeholder: 'Semua pelanggan', choose: customer } });
        await expect(saleRow(page, a2)).toBeVisible();
        await expect(saleRow(page, a1)).toHaveCount(0);
        await expectCard(page, 'Pendapatan Bersih', rp(27000));
        await expectCard(page, 'Total Profit', rp(13500));
        await expectCard(page, 'Diskon Diberikan', rp(3000));

        // Reset clears every filter: reopening shows an empty invoice box and "all customers".
        await resetFilters(page);
        await expect(page).not.toHaveURL(/invoice=[^&]/);
        await expect(page.getByPlaceholder('TRX-...')).toHaveValue('');
        await expect(page.getByRole('button', { name: 'Semua pelanggan' })).toBeVisible();

        // Only A1 was rung up by the E2E Cashier (A2 by the manager).
        await applyFilters(page, { invoice: prefix, option: { placeholder: 'Semua kasir', choose: 'E2E Cashier' } });
        await expect(saleRow(page, a1)).toBeVisible();
        await expect(saleRow(page, a2)).toHaveCount(0);
        await expectCard(page, 'Pendapatan Bersih', rp(35000));
        await expectCard(page, 'Item Terjual', '3', '1 transaksi');
    });
});
