import { test, expect } from '../../support/fixtures.js';
import { role } from '../../support/inventory.js';
import { fillWaste, wasteRow } from '../../support/waste.js';

test.describe('82-stock-waste', () => {
    // A limit of 0 makes every record wait: it is Menunggu persetujuan and the stock is untouched (AC-05).
    test('waste above the outlet limit waits for approval and moves no stock', { tag: ['@REQ-011-AC-05'] }, async ({ asRole, arrange, uniq }) => {
        const page = await asRole(role('manager'));
        const { bean, bean_id } = arrange('inventory.recipe', { prefix: uniq('Wst'), beans: 1000 });
        arrange('waste.limit', { value: 0 });
        const note = uniq('tunggu');

        await fillWaste(page, { item: bean, qty: 100, note });
        await page.getByRole('button', { name: 'Simpan waste' }).click();

        await expect(page.getByText('Waste menunggu persetujuan. Stok belum berubah.')).toBeVisible();
        const row = wasteRow(page, note);
        await expect(row.getByText('Menunggu persetujuan', { exact: true })).toBeVisible();
        expect(arrange('waste.stock', { ids: bean_id })).toEqual([[1000, 1000]]);
    });
});
