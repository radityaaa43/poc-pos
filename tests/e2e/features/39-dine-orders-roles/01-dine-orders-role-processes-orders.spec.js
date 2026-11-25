import { test, expect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';

const dineOrders = roles.find((role) => role.key === 'dine-orders');

test.describe('39-dine-orders-roles', () => {
    test('the dine-orders role accepts an order but cannot reach areas or tables', async ({ asRole, arrange, uniq }) => {
        const { table_name, token } = arrange('dine.table', { area: uniq('Area'), name: uniq('M') });
        const { product_id } = arrange('product', { title: uniq('Sate'), stock: 5 });
        const { access_token } = arrange('dine.order', { token, product_id, qty: 1 });
        arrange('open-shift', { cashier: dineOrders.email, cash: 50000 });

        const page = await asRole(dineOrders);
        await page.goto('/dashboard/dine-orders');
        const card = page.locator('div.rounded-2xl', { hasText: table_name });
        await expect(card).toContainText('Menunggu');

        page.once('dialog', (confirm) => confirm.accept());
        await card.getByRole('button', { name: 'Terima' }).click();
        await expect(card).toContainText('Diterima');
        expect(arrange('dine.order-state', { access_token, product_id })).toMatchObject({ status: 'accepted', product_stock: 4, warehouse_stock: 4 });

        // This role has dine-orders-* only.
        expect((await page.goto('/dashboard/dine-tables')).status()).toBe(403);
        expect((await page.goto('/dashboard/dine-areas')).status()).toBe(403);
    });

    test('the dine-orders role can reject an order too', async ({ asRole, arrange, uniq }) => {
        const { table_name, token } = arrange('dine.table', { area: uniq('Area'), name: uniq('M') });
        const { product_id } = arrange('product', { title: uniq('Pisang'), stock: 5 });
        const { access_token } = arrange('dine.order', { token, product_id, qty: 1 });

        const page = await asRole(dineOrders);
        await page.goto('/dashboard/dine-orders');
        const card = page.locator('div.rounded-2xl', { hasText: table_name });
        page.once('dialog', (prompt) => prompt.accept(''));
        await card.getByRole('button', { name: 'Tolak' }).click();

        await expect(card).toHaveCount(0);
        expect(arrange('dine.order-state', { access_token })).toMatchObject({ status: 'rejected' });
    });
});
