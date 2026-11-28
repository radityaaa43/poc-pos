import { test, expect } from '../../support/fixtures.js';
import { role } from '../../support/inventory.js';
import { rp } from '../../support/reports.js';

// REQ-011 AC-11 + the success metric: waste per recorder and day with near-limit counts, and waste
// cost as a percent of sales cost.
test.describe('82-stock-waste', () => {
    test('shows waste per recorder and day and the waste percent of sales cost', { tag: ['@REQ-011-AC-11'] }, async ({ asRole, arrange }) => {
        const { date, manager, cashier } = arrange('waste.seed-recorders');
        const page = await asRole(role('manager'));

        await page.goto('/dashboard/reports/waste');
        await page.locator('input[type=date]').first().fill(date);
        await page.locator('input[type=date]').nth(1).fill(date);
        const reload = page.waitForResponse((response) => response.request().method() === 'GET' && response.url().includes('/reports/waste'));
        await page.getByRole('button', { name: 'Terapkan' }).click();
        await reload;
        await page.waitForLoadState('networkidle');

        // Waste cost 340.000 / sales cost 340.000 = 100,0%.
        await expect(page.getByRole('region', { name: 'Waste terhadap HPP penjualan' })).toContainText('100,0%');

        const table = page.getByRole('region', { name: 'Waste per Pencatat dan Hari' });

        // Manager: 3 records, 240.000, two at or near the limit.
        const managerCells = table.getByRole('row', { name: new RegExp(manager) }).getByRole('cell');
        await expect(managerCells.nth(0)).toHaveText(date);
        await expect(managerCells.nth(1)).toHaveText('3');
        await expect(managerCells.nth(2)).toContainText(rp(240000));
        await expect(managerCells.nth(3)).toContainText('2');
        await expect(managerCells.nth(3)).toContainText('Mendekati batas');

        // Cashier: 1 record, 100.000, exactly at the limit.
        const cashierCells = table.getByRole('row', { name: new RegExp(cashier) }).getByRole('cell');
        await expect(cashierCells.nth(1)).toHaveText('1');
        await expect(cashierCells.nth(2)).toContainText(rp(100000));
        await expect(cashierCells.nth(3)).toContainText('Mendekati batas');
    });
});
