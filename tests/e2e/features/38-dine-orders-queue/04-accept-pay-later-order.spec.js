import { test, expect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';

const manager = roles.find((role) => role.key === 'manager');
const cashier = roles.find((role) => role.key === 'cashier');

// REQ-003 AC-03: a guest who chose Bayar Belakangan has the flagged items sent to the kitchen the moment staff accept,
// before anything is paid. The bill is an unpaid open bill under the table's name, with no receivable. Accepting does
// not depend on the outlet setting (the guest ordered under the rule shown), so the step leaves the setting alone.
test.describe('38-dine-orders-queue', () => {
    test.describe.configure({ timeout: 240_000 });

    test('accepting a pay-later order sends its flagged items to the kitchen before payment: an unpaid open bill on the table, no receivable', { tag: ['@REQ-003-AC-03'] }, async ({ asRole, arrange, uniq }) => {
        const { table_name, token } = arrange('dine.table', { area: uniq('Area'), name: uniq('M') });
        const title = uniq('Rendang');
        const { product_id } = arrange('product', { title, stock: 5 });
        arrange('kitchen.flag', { product_id });
        const { access_token } = arrange('dine.order', { token, product_id, qty: 2, option: 'pay_later' });
        arrange('open-shift', { cashier: manager.email, cash: 100000 });

        const page = await asRole(manager);
        await page.goto('/dashboard/dine-orders');
        const card = page.locator('div.rounded-2xl', { hasText: table_name });
        await expect(card).toContainText('Bayar Belakangan');

        page.once('dialog', (confirm) => confirm.accept());
        await card.getByRole('button', { name: 'Terima' }).click();
        await expect(card.getByRole('button', { name: 'Terima' })).toHaveCount(0);
        await expect(card).toContainText('Belum Lunas');

        // Unpaid, yet the kitchen already has the line, as accepted. Nothing is owed on credit.
        const state = arrange('dine.order-state', { access_token, product_id });
        expect(state).toMatchObject({ status: 'accepted', payment_option: 'pay_later', payment_status: 'unpaid', receivables: 0, kitchen_items: 1 });
        expect(state.invoice).toMatch(/^TRX-/);
        expect(arrange('kitchen.items', { invoice: state.invoice })).toEqual({ count: 1, product_ids: [product_id], statuses: ['accepted'] });
        expect(arrange('paylater.sale-state', { invoice: state.invoice })).toMatchObject({
            payment_status: 'unpaid', payment_method: 'open_bill', is_pay_later: true, receivables: 0,
        });

        // The POS "Belum Lunas" strip lists the bill under the table's name.
        await page.goto('/dashboard/transactions');
        await page.getByRole('button', { name: /Belum Lunas/ }).click();
        const bill = page.getByTestId('open-bills').getByRole('listitem').filter({ hasText: table_name });
        await expect(bill).toHaveCount(1);
        await expect(bill).toContainText(state.invoice);

        // The kitchen display shows the item marked Belum lunas.
        const kitchen = await asRole(cashier);
        await kitchen.goto('/dashboard/kitchen');
        const kitchenCard = kitchen.locator('article', { hasText: table_name });
        await expect(kitchenCard.getByRole('heading', { name: table_name, exact: true })).toBeVisible();
        await expect(kitchenCard).toContainText('Belum lunas');
        await expect(kitchenCard).toContainText(`2 × ${title}`);
        await expect(kitchenCard).toContainText('Diterima');
    });
});
