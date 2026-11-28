import { test, expect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';
import { applyFilters, expectCard, rp } from '../../support/reports.js';

const manager = roles.find((role) => role.key === 'manager');

// REQ-011 AC-10: waste cost is its own line on the profit report, sales profit does not move, and a
// filter waste has no value for (invoice) hides the line instead of showing a wrong number (BR-1).
test.describe('profit report: waste cost', () => {
    test('waste is its own line, sales profit stays, and an invoice filter hides it', { tag: ['@REQ-011-AC-10'] }, async ({ asRole, arrange }) => {
        const { date } = arrange('waste.seed-report');
        const page = await asRole(manager);

        await page.goto('/dashboard/reports/profits');
        await applyFilters(page, { start: date, end: date });

        // Sale profit 15.000; waste 2.000 recorded - 400 cancelled in the period = 1.600.
        await expectCard(page, 'Total Profit', rp(15000));
        await expectCard(page, 'Biaya waste', rp(1600), 'Tidak termasuk HPP penjualan');
        await expectCard(page, 'Profit setelah waste', rp(13400));

        await applyFilters(page, { invoice: 'WST' });
        await expectCard(page, 'Total Profit', rp(15000));
        await expect(page.getByText('Biaya waste', { exact: true })).toHaveCount(0);
        await expect(
            page.getByText('Biaya waste tidak ditampilkan saat filter faktur, kasir atau pelanggan aktif'),
        ).toBeVisible();
    });
});
