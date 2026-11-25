import { test, expect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';
import { applyFilters, resetFilters, expectCard, rp, saleRow } from '../../support/reports.js';

const manager = roles.find((role) => role.key === 'manager');

test.describe('profit report: filters', () => {
    test('date range, customer and cashier narrow the totals', async ({ asRole, arrange }) => {
        test.slow(); // several filter round trips
        const { prefix, a1, a2, customer } = arrange('reports.sales');
        const page = await asRole(manager);

        await page.goto('/dashboard/reports/profits');
        await applyFilters(page, { invoice: prefix, start: '2025-03-01', end: '2025-03-15' });
        await expectCard(page, 'Total Profit', rp(18000));
        await expectCard(page, 'Rata-rata Profit', rp(18000), '1 transaksi');
        await expectCard(page, 'Margin Kotor', '51.43%');
        await expectCard(page, 'Transaksi Terbaik', a1, rp(18000));
        await expect(saleRow(page, a2)).toHaveCount(0);

        await applyFilters(page, { start: '2025-03-16', end: '2025-03-31' });
        await expectCard(page, 'Total Profit', rp(13500));
        await expectCard(page, 'Margin Kotor', '50%');
        await expectCard(page, 'Transaksi Terbaik', a2, rp(13500));
        await expect(saleRow(page, a1)).toHaveCount(0);

        // Whole month again, then by customer (A2 only).
        await applyFilters(page, { start: '', end: '', option: { placeholder: 'Semua pelanggan', choose: customer } });
        await expectCard(page, 'Total Profit', rp(13500));
        await expect(saleRow(page, a2)).toBeVisible();
        await expect(saleRow(page, a1)).toHaveCount(0);

        // A cashier who rang up A1 only; the customer filter is cleared by choosing the placeholder-less reset (X).
        await resetFilters(page);
        await applyFilters(page, { invoice: prefix, option: { placeholder: 'Semua kasir', choose: 'E2E Cashier' } });
        await expectCard(page, 'Total Profit', rp(18000));
        await expectCard(page, 'Transaksi Terbaik', a1);

        // Nothing matches: zeroes, a dash for the best sale and the empty state.
        await applyFilters(page, { start: '2024-01-01', end: '2024-12-31' });
        await expectCard(page, 'Total Profit', rp(0));
        await expectCard(page, 'Margin Kotor', '0%');
        await expectCard(page, 'Transaksi Terbaik', '-', rp(0));
        await expect(page.getByRole('heading', { name: 'Tidak Ada Data' })).toBeVisible();
    });
});
