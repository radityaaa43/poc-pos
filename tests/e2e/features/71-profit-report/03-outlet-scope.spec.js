import { test, expect } from '../../support/fixtures.js';
import { login } from '../../support/login.js';
import { applyFilters, expectCard, rp, saleRow, switchOutlet } from '../../support/reports.js';

test.describe('profit report: outlet scope', () => {
    test('a super admin sees only the active outlet and switches to the other', async ({ page, arrange }) => {
        test.slow(); // a fresh login plus an outlet switch
        const { prefix, a1, a2, b1, other_outlet } = arrange('reports.sales');
        arrange('reports.no-open-shift'); // an open shift of the super admin locks the outlet switcher
        // A fresh login keeps the outlet switch out of the shared saved session.
        await login(page, 'superadmin@e2e.test');
        await expect(page).toHaveURL(/dashboard\/transactions$/);

        await page.goto('/dashboard/reports/profits');
        await applyFilters(page, { invoice: prefix });
        await expectCard(page, 'Total Profit', rp(31500));
        await expect(saleRow(page, b1)).toHaveCount(0);

        await switchOutlet(page, other_outlet);
        await expect(saleRow(page, b1)).toBeVisible();
        await expect(saleRow(page, a1)).toHaveCount(0);
        await expect(saleRow(page, a2)).toHaveCount(0);
        await expectCard(page, 'Total Profit', rp(13000));
        await expectCard(page, 'Margin Kotor', '52%');
        await expectCard(page, 'Transaksi Terbaik', b1, rp(13000));
    });
});
