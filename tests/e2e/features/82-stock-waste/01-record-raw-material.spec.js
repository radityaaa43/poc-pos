import { test, expect } from '../../support/fixtures.js';
import { role } from '../../support/inventory.js';
import { fillWaste, wasteRow } from '../../support/waste.js';

test.describe('82-stock-waste', () => {
    test('raw material waste under the limit is recorded and takes the stock at once', { tag: ['@REQ-011-AC-01', '@REQ-011-AC-04'] }, async ({ asRole, arrange, uniq }) => {
        const page = await asRole(role('manager'));
        const { bean, bean_id } = arrange('inventory.recipe', { prefix: uniq('Wst'), beans: 1000 });
        const note = uniq('tumpah');

        await fillWaste(page, { item: bean, qty: 100, note });
        // 100 x the average cost of Rp 250, shown before saving.
        await expect(page.getByText(/Perkiraan nilai waste: Rp\s?25\.000/)).toBeVisible();
        await page.getByRole('button', { name: 'Simpan waste' }).click();

        await expect(page.getByText('Waste tercatat dan stok sudah dipotong.')).toBeVisible();
        const row = wasteRow(page, note);
        await expect(row.getByText('Tercatat', { exact: true })).toBeVisible();
        await expect(row.locator('summary')).toContainText(/Rusak.*Rp\s?25\.000/);
        expect(arrange('waste.stock', { ids: bean_id })).toEqual([[900, 900]]);
    });
});
