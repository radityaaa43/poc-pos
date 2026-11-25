import { test, expect } from '../../support/fixtures.js';
import { login } from '../../support/login.js';
import { roles } from '../../support/roles.js';
import { applyFilters, expectCard, rp, saleRow, switchOutlet } from '../../support/reports.js';

const manager = roles.find((role) => role.key === 'manager');
const superAdmin = roles.find((role) => role.key === 'super-admin');

test.describe('sales report: outlet scope', () => {
    test('a manager only sees the outlet they are assigned to', async ({ asRole, arrange }) => {
        const { prefix, a1, a2, b1 } = arrange('reports.sales');
        const page = await asRole(manager);

        await page.goto('/dashboard/reports/sales');
        await applyFilters(page, { invoice: prefix });

        await expect(saleRow(page, a1)).toBeVisible();
        await expect(saleRow(page, a2)).toBeVisible();
        await expect(saleRow(page, b1)).toHaveCount(0);
        await expectCard(page, 'Pendapatan Bersih', rp(62000));
        // One outlet only: there is nothing to switch to.
        await expect(page.getByTitle('Pilih outlet')).toHaveCount(0);
    });

    test('a super admin sees the active outlet and follows the outlet switcher', async ({ page, arrange }) => {
        test.slow(); // a fresh login plus an outlet switch
        const { prefix, a1, a2, b1, other_outlet } = arrange('reports.sales');
        arrange('reports.no-open-shift'); // an open shift of the super admin locks the outlet switcher
        // A fresh login: switching the outlet is stored in the session, which must not leak into the shared saved one.
        await login(page, superAdmin.email);
        await expect(page).toHaveURL(/dashboard\/transactions$/);

        await page.goto('/dashboard/reports/sales');
        await applyFilters(page, { invoice: prefix });
        await expect(saleRow(page, a1)).toBeVisible();
        await expect(saleRow(page, b1)).toHaveCount(0);
        await expectCard(page, 'Pendapatan Bersih', rp(62000));

        await switchOutlet(page, other_outlet);
        await expect(saleRow(page, b1)).toBeVisible();
        await expect(saleRow(page, a1)).toHaveCount(0);
        await expect(saleRow(page, a2)).toHaveCount(0);
        await expectCard(page, 'Pendapatan Bersih', rp(25000));
        await expectCard(page, 'Total Profit', rp(13000));
        await expectCard(page, 'Item Terjual', '1', '1 transaksi');
    });
});
