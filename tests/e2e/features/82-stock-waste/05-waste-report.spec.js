import { test, expect } from '../../support/fixtures.js';
import { role } from '../../support/inventory.js';
import { rp } from '../../support/reports.js';

// REQ-011 AC-11: the waste report shows quantity and value per item and count and value per reason,
// with a record cancelled in the period subtracted.
test.describe('82-stock-waste', () => {
    test('reports qty and value per item and per reason, a cancellation subtracted', { tag: ['@REQ-011-AC-11'] }, async ({ asRole, arrange }) => {
        const { date, item } = arrange('waste.seed-report');
        const page = await asRole(role('manager'));

        await page.goto('/dashboard/reports/waste');
        await expect(page.locator('main').getByRole('heading', { name: 'Laporan Waste', level: 1 })).toBeVisible();

        await page.locator('input[type=date]').first().fill(date);
        await page.locator('input[type=date]').nth(1).fill(date);
        const reload = page.waitForResponse((response) => response.request().method() === 'GET' && response.url().includes('/reports/waste'));
        await page.getByRole('button', { name: 'Terapkan' }).click();
        await reload;
        await page.waitForLoadState('networkidle');

        // 10 units worth 2.000 recorded, 2 units worth 400 recorded before the period and cancelled in it.
        await expect(page.getByRole('region', { name: 'Total biaya waste' })).toContainText(rp(1600));

        const itemCells = page.getByRole('row', { name: new RegExp(item) }).getByRole('cell');
        await expect(itemCells.nth(0)).toHaveText('8');
        await expect(itemCells.nth(1)).toContainText(rp(1600));

        const reasonCells = page.getByRole('row', { name: /Kedaluwarsa/ }).getByRole('cell');
        await expect(reasonCells.nth(0)).toHaveText('1');
        await expect(reasonCells.nth(1)).toContainText(rp(1600));
    });
});
