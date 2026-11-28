import { test, expect } from '../../support/fixtures.js';
import { role } from '../../support/inventory.js';
import { fillWaste, wasteRow } from '../../support/waste.js';

test.describe('82-stock-waste', () => {
    test('menu waste takes the recipe ingredients and is worth the sum of their costs', { tag: ['@REQ-011-AC-02'] }, async ({ asRole, arrange, uniq }) => {
        const page = await asRole(role('manager'));
        const { recipe, bean, bean_id, water_id, cup_id } = arrange('inventory.recipe', { prefix: uniq('Mnu'), beans: 1000 });
        const note = uniq('salah buat');

        // Two menus of 18 beans (Rp 250), 200 water (Rp 1) and 1 cup (Rp 1,500).
        await fillWaste(page, { item: recipe, qty: 2, note });
        await expect(page.getByText(/Perkiraan nilai waste: Rp\s?12\.400/)).toBeVisible();
        await page.getByRole('button', { name: 'Simpan waste' }).click();

        const row = wasteRow(page, note);
        await row.locator('summary').click();
        await expect(row.getByRole('row').filter({ hasText: bean })).toContainText(/36.*Rp\s?9\.000/);
        await expect(row.getByRole('row')).toHaveCount(4);
        await expect(row.locator('summary')).toContainText(/Rp\s?12\.400/);
        expect(arrange('waste.stock', { ids: `${bean_id},${water_id},${cup_id}` })).toEqual([[964, 964], [9600, 9600], [98, 98]]);
    });
});
