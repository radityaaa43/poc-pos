import { test, expect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';
import { applyFilters, expectCard, rp, tableUnder } from '../../support/reports.js';

const manager = roles.find((role) => role.key === 'manager');

// Insights has no invoice filter, so the step filters on the category reports.sales creates for itself.
test.describe('sales insights: summary and rankings', () => {
    test('cards, top sellers and cashier leaderboard match the arranged sales', async ({ asRole, arrange }) => {
        test.slow(); // several filter round trips
        const { category, kopi, roti, teh } = arrange('reports.sales');
        const page = await asRole(manager);

        await page.goto('/dashboard/reports/insights');
        await expect(page.getByRole('main').getByRole('heading', { name: 'Advanced Sales Insights', level: 1 })).toBeVisible();
        await applyFilters(page, { option: { placeholder: 'Semua kategori', choose: category } });

        // A1 + A2 of the manager's outlet; profit is read from the locked HPP.
        await expectCard(page, 'Pendapatan', rp(62000), '2 transaksi');
        await expectCard(page, 'Profit', rp(31500), new RegExp(`Rata-rata\\s${rp(31000).source}`));
        await expectCard(page, 'Item Terjual', '6', new RegExp(`Diskon manual\\s${rp(3000).source}`));
        await expectCard(page, 'Kasir Aktif di Filter', '2');

        // Top sellers by quantity: coffee 2 + 3, bread 1; tea belongs to the other outlet.
        const top = tableUnder(page, 'Top Selling Products');
        await expect(top.getByRole('row')).toHaveCount(3);
        await expect(top.getByRole('row').nth(1)).toContainText(kopi);
        await expect(top.getByRole('row').nth(1)).toContainText(category);
        await expect(top.getByRole('row').nth(1)).toContainText(/5\s*Rp\s50\.000\s*Rp\s24\.500/);
        await expect(top.getByRole('row').nth(2)).toContainText(roti);
        await expect(top.getByRole('row').nth(2)).toContainText(/1\s*Rp\s15\.000\s*Rp\s7\.000/);
        await expect(top.getByText(teh)).toHaveCount(0);

        // Leaderboard: both rang up 3 items, the larger takings first.
        const cashiers = tableUnder(page, 'Cashier Performance');
        await expect(cashiers.getByRole('row')).toHaveCount(3);
        await expect(cashiers.getByRole('row').nth(1)).toContainText(/E2E Cashier\s*1\s*3\s*Rp\s35\.000\s*Rp\s18\.000\s*Rp\s35\.000/);
        await expect(cashiers.getByRole('row').nth(2)).toContainText(/E2E Manager\s*1\s*3\s*Rp\s27\.000\s*Rp\s13\.500\s*Rp\s27\.000/);
    });
});
