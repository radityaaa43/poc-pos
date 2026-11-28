import { test, expect } from '../../support/fixtures.js';
import { role } from '../../support/inventory.js';
import { fillWaste } from '../../support/waste.js';

test.describe('82-stock-waste', () => {
    test('waste above the stock is refused, naming the item and the stock left', { tag: ['@REQ-011-AC-03'] }, async ({ asRole, arrange, uniq }) => {
        const page = await asRole(role('manager'));
        const { bean, bean_id } = arrange('inventory.recipe', { prefix: uniq('Krg'), beans: 50 });

        await fillWaste(page, { item: bean, qty: 51, note: uniq('lebih') });
        await page.getByRole('button', { name: 'Simpan waste' }).click();

        await expect(page.getByRole('alert')).toHaveText(`Stok ${bean} tidak mencukupi. Tersedia: 50.`);
        await expect(page).toHaveURL(/stock-waste\/create/);
        expect(arrange('waste.stock', { ids: bean_id })).toEqual([[50, 50]]);
    });
});
