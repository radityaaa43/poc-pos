import { test, expect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';

const cashier = roles.find((role) => role.key === 'cashier');
const manager = roles.find((role) => role.key === 'manager');

// reports-access guards sales and insights, profits-access the profit report; the cashier role holds neither.
test.describe('reports: access by role', () => {
    for (const path of ['/dashboard/reports/sales', '/dashboard/reports/profits', '/dashboard/reports/insights']) {
        test(`the cashier is refused ${path}`, async ({ asRole }) => {
            const page = await asRole(cashier);

            const response = await page.goto(path);
            expect(response.status()).toBe(403);
        });
    }

    test('the manager sees the report pages in the menu and opens them', async ({ asRole }) => {
        const page = await asRole(manager);

        await page.goto('/dashboard/reports/sales');
        await expect(page.getByRole('main').getByRole('heading', { name: 'Laporan Penjualan', level: 1 })).toBeVisible();
        await page.goto('/dashboard/reports/profits');
        await expect(page.getByRole('main').getByRole('heading', { name: 'Laporan Keuntungan', level: 1 })).toBeVisible();
        await page.goto('/dashboard/reports/insights');
        await expect(page.getByRole('main').getByRole('heading', { name: 'Advanced Sales Insights', level: 1 })).toBeVisible();
    });
});
