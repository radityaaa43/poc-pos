import { test, expect } from '../../support/fixtures.js';
import { login } from '../../support/login.js';
import { roles } from '../../support/roles.js';
import { applyFilters, expectCard, rp, switchOutlet, tableUnder } from '../../support/reports.js';

const manager = roles.find((role) => role.key === 'manager');

test.describe('sales insights: filters and outlet scope', () => {
    test('date range and customer narrow the cards and tables', async ({ asRole, arrange }) => {
        test.slow(); // several filter round trips
        const { category, customer, kopi, roti } = arrange('reports.sales');
        const page = await asRole(manager);

        await page.goto('/dashboard/reports/insights');
        await applyFilters(page, { start: '2025-03-01', end: '2025-03-15', option: { placeholder: 'Semua kategori', choose: category } });

        // 1-15 March: A1 only.
        await expectCard(page, 'Pendapatan', rp(35000), '1 transaksi');
        await expectCard(page, 'Profit', rp(18000));
        await expectCard(page, 'Item Terjual', '3');
        await expectCard(page, 'Kasir Aktif di Filter', '1');
        await expect(tableUnder(page, 'Top Selling Products').getByRole('row').nth(1)).toContainText(/2\s*Rp\s20\.000\s*Rp\s11\.000/);
        await expect(tableUnder(page, 'Top Selling Products').getByText(roti)).toBeVisible();

        // 16-31 March: A2 only, so the discount shows and bread was not sold.
        await applyFilters(page, { start: '2025-03-16', end: '2025-03-31' });
        await expectCard(page, 'Pendapatan', rp(27000), '1 transaksi');
        await expectCard(page, 'Profit', rp(13500));
        await expectCard(page, 'Item Terjual', '3');
        await expect(tableUnder(page, 'Top Selling Products').getByText(roti)).toHaveCount(0);
        await expect(tableUnder(page, 'Top Selling Products').getByText(kopi)).toBeVisible();

        // A range with no sale: zeroes and the empty rankings.
        await applyFilters(page, { start: '2024-01-01', end: '2024-12-31' });
        await expectCard(page, 'Pendapatan', rp(0), '0 transaksi');
        await expect(page.getByText('Belum ada data top selling pada periode ini.')).toBeVisible();
        await expect(page.getByText('Belum ada data performa kasir pada periode ini.')).toBeVisible();

        // The customer of A2 over the whole period.
        await applyFilters(page, { start: '', end: '', option: { placeholder: 'Semua pelanggan', choose: customer } });
        await expectCard(page, 'Pendapatan', rp(27000), '1 transaksi');
        await expectCard(page, 'Kasir Aktif di Filter', '1');
    });

    test('a super admin sees the active outlet and follows the outlet switcher', async ({ page, arrange }) => {
        test.slow(); // a fresh login plus an outlet switch
        const { category, kopi, teh, other_outlet } = arrange('reports.sales');
        // A fresh login keeps the outlet switch out of the shared saved session.
        await login(page, 'superadmin@e2e.test');
        await expect(page).toHaveURL(/dashboard\/transactions$/);

        await page.goto('/dashboard/reports/insights');
        await applyFilters(page, { option: { placeholder: 'Semua kategori', choose: category } });
        await expectCard(page, 'Pendapatan', rp(62000), '2 transaksi');
        await expect(tableUnder(page, 'Top Selling Products').getByText(kopi)).toBeVisible();
        await expect(tableUnder(page, 'Top Selling Products').getByText(teh)).toHaveCount(0);

        await switchOutlet(page, other_outlet);
        await expectCard(page, 'Pendapatan', rp(25000), '1 transaksi');
        await expectCard(page, 'Profit', rp(13000));
        await expect(tableUnder(page, 'Top Selling Products').getByText(teh)).toBeVisible();
        await expect(tableUnder(page, 'Top Selling Products').getByText(kopi)).toHaveCount(0);
    });
});
