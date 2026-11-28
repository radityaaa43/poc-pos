import { test, expect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';
import { returnPortions, sellMenu } from '../../support/modifiers.js';

const manager = roles.find((role) => role.key === 'manager');

test.describe('48-modifiers: returning a line with options', () => {
    test.describe.configure({ timeout: 120_000 });

    test('the return lists the sold options and note and gives their stock back', { tag: ['@REQ-008-AC-14'] }, async ({ asRole, arrange, uniq }) => {
        const title = uniq('Kopi');
        const { product_id: product, milk_id: milk } = arrange('modifier-menu', { title });
        arrange('pos.open-shift', { cashier: 'manager@e2e.test', cash: 100000 });
        const page = await asRole(manager);
        const invoice = await sellMenu(page, title, { options: ['Large', 'Less'], portions: 2, amount: 50000, note: 'es sedikit' });
        // Large takes 2 milk a portion: 10 - 4.
        expect(arrange('modifier-stock', { product: milk }).stock).toBe(6);
        arrange('modifier-change', { product, option: 'Large', rename: 'Jumbo' });

        await returnPortions(page, invoice, 1, async () => {
            await expect(page.getByText('+ Large 5.000')).toBeVisible();
            await expect(page.getByText('Catatan: es sedikit')).toBeVisible();
            await expect(page.getByRole('checkbox')).toBeChecked();
        });

        await expect(page.getByText('+ Large 5.000')).toBeVisible();
        expect(arrange('modifier-stock', { product: milk }).stock).toBe(8);
        expect(arrange('modifier-stock', { product }).stock).toBe(19);
    });
});
