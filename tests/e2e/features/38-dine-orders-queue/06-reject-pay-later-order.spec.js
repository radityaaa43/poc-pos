import { test, expect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';

const manager = roles.find((role) => role.key === 'manager');
const cashier = roles.find((role) => role.key === 'cashier');

// REQ-003 AC-03 (BR-6): a pay-later order only reaches the kitchen when staff accept it. Rejecting it leaves no bill
// and no kitchen item, even for a product that needs preparation.
test.describe('38-dine-orders-queue', () => {
    test.describe.configure({ timeout: 240_000 });

    test('rejecting a pay-later order sends nothing to the kitchen: no bill, no kitchen item, stock untouched', { tag: ['@REQ-003-AC-03'] }, async ({ asRole, arrange, uniq }) => {
        const { table_name, token } = arrange('dine.table', { area: uniq('Area'), name: uniq('M') });
        const title = uniq('Ayam');
        const { product_id } = arrange('product', { title, stock: 5 });
        arrange('kitchen.flag', { product_id });
        const { access_token } = arrange('dine.order', { token, product_id, qty: 1, option: 'pay_later' });

        const page = await asRole(manager);
        await page.goto('/dashboard/dine-orders');
        const card = page.locator('div.rounded-2xl', { hasText: table_name });
        await expect(card).toContainText('Bayar Belakangan');
        page.once('dialog', (prompt) => prompt.accept('Dapur tutup'));
        await card.getByRole('button', { name: 'Tolak' }).click();
        await expect(page.locator('div.rounded-2xl', { hasText: table_name })).toHaveCount(0);

        expect(arrange('dine.order-state', { access_token, product_id })).toMatchObject({
            status: 'rejected', payment_option: 'pay_later', invoice: null, kitchen_items: 0, receivables: 0, product_stock: 5, warehouse_stock: 5,
        });

        // The kitchen display has loaded (its heading is there) and has no card for this table.
        const kitchen = await asRole(cashier);
        await kitchen.goto('/dashboard/kitchen');
        await expect(kitchen.getByRole('main').getByRole('heading', { name: 'Layar Dapur', exact: true })).toBeVisible();
        await expect(kitchen.locator('article', { hasText: table_name })).toHaveCount(0);
        await expect(kitchen.getByText(title)).toHaveCount(0);
    });
});
