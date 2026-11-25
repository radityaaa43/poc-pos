import { test, expect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';
import { applyFilters, expectCard, rp, saleRow } from '../../support/reports.js';

const manager = roles.find((role) => role.key === 'manager');

// Arranged by reports.sales: A1 (10 Mar, E2E Cashier) 35.000 / 3 items / profit 18.000, A2 (20 Mar, E2E Manager,
// customer, discount 3.000) 27.000 / 3 items / profit 13.500; B1 sits in another outlet and must stay out.
test.describe('sales report: totals and date filter', () => {
    test('totals, rows and date range match the arranged sales', async ({ asRole, arrange }) => {
        test.slow(); // several filter round trips
        const { prefix, a1, a2, b1, customer } = arrange('reports.sales');
        const page = await asRole(manager);

        await page.goto('/dashboard/reports/sales');
        await expect(page.getByRole('main').getByRole('heading', { name: 'Laporan Penjualan', level: 1 })).toBeVisible();
        await applyFilters(page, { invoice: prefix });

        // Two sales of the manager's outlet: 35.000 + 27.000, discount 3.000, profit from the locked HPP.
        await expectCard(page, 'Pendapatan Bersih', rp(62000), 'Total setelah diskon');
        await expectCard(page, 'Total Profit', rp(31500), new RegExp(`Rata-rata\\s${rp(31000).source}`));
        await expectCard(page, 'Item Terjual', '6', '2 transaksi');
        await expectCard(page, 'Diskon Diberikan', rp(3000));

        // Newest first; each row shows cashier, customer, item count, total and profit.
        await expect(page.getByRole('row')).toHaveCount(3);
        await expect(saleRow(page, a2)).toContainText('E2E Manager');
        await expect(saleRow(page, a2)).toContainText('2025-03-20');
        await expect(saleRow(page, a2)).toContainText(customer);
        await expect(saleRow(page, a2)).toContainText(rp(27000));
        await expect(saleRow(page, a2)).toContainText(rp(13500));
        await expect(saleRow(page, a1)).toContainText('E2E Cashier');
        await expect(saleRow(page, a1)).toContainText('2025-03-10');
        await expect(saleRow(page, a1)).toContainText(rp(35000));
        await expect(saleRow(page, a1)).toContainText(rp(18000));
        await expect(saleRow(page, b1)).toHaveCount(0);

        // 1-15 March: only A1.
        await applyFilters(page, { start: '2025-03-01', end: '2025-03-15' });
        await expectCard(page, 'Pendapatan Bersih', rp(35000));
        await expectCard(page, 'Total Profit', rp(18000));
        await expectCard(page, 'Item Terjual', '3', '1 transaksi');
        await expectCard(page, 'Diskon Diberikan', rp(0));
        await expect(saleRow(page, a1)).toBeVisible();
        await expect(saleRow(page, a2)).toHaveCount(0);

        // 16-31 March: only A2, with its discount.
        await applyFilters(page, { start: '2025-03-16', end: '2025-03-31' });
        await expectCard(page, 'Pendapatan Bersih', rp(27000));
        await expectCard(page, 'Diskon Diberikan', rp(3000));
        await expect(saleRow(page, a2)).toBeVisible();
        await expect(saleRow(page, a1)).toHaveCount(0);

        // A range with no sale: zeroes and the empty state.
        await applyFilters(page, { start: '2024-01-01', end: '2024-12-31' });
        await expectCard(page, 'Pendapatan Bersih', rp(0));
        await expectCard(page, 'Item Terjual', '0', '0 transaksi');
        await expect(page.getByRole('heading', { name: 'Tidak Ada Data' })).toBeVisible();
        await expect(page.getByText('Tidak ada transaksi sesuai filter.')).toBeVisible();
    });
});
