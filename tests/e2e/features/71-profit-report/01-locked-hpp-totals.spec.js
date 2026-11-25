import { test, expect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';
import { applyFilters, expectCard, rp, saleRow } from '../../support/reports.js';

const manager = roles.find((role) => role.key === 'manager');

// reports.sales locks each line's HPP in details.cost_total and then raises the products' buy_price to 99.999:
// a profit read from the current buy price would be negative, so these numbers prove the locked HPP is used.
test.describe('profit report: totals from the locked HPP', () => {
    test('profit, margin and best sale match the arranged sales', async ({ asRole, arrange }) => {
        const { prefix, a1, a2, b1 } = arrange('reports.sales');
        const page = await asRole(manager);

        await page.goto('/dashboard/reports/profits');
        await expect(page.getByRole('main').getByRole('heading', { name: 'Laporan Keuntungan', level: 1 })).toBeVisible();
        await applyFilters(page, { invoice: prefix });

        // A1 18.000 + A2 13.500 over revenue 35.000 + 27.000 (the manager's outlet only).
        await expectCard(page, 'Total Profit', rp(31500), 'Akumulasi bersih');
        await expectCard(page, 'Rata-rata Profit', rp(15750), '2 transaksi');
        await expectCard(page, 'Margin Kotor', '50.81%', 'Profit vs penjualan');
        await expectCard(page, 'Transaksi Terbaik', a1, rp(18000));

        await expect(page.getByRole('row')).toHaveCount(3);
        await expect(saleRow(page, a1)).toContainText('E2E Cashier');
        await expect(saleRow(page, a1)).toContainText(rp(35000));
        await expect(saleRow(page, a1)).toContainText(rp(18000));
        await expect(saleRow(page, a2)).toContainText('E2E Manager');
        await expect(saleRow(page, a2)).toContainText(rp(27000));
        await expect(saleRow(page, a2)).toContainText(rp(13500));
        await expect(saleRow(page, b1)).toHaveCount(0);
    });
});
