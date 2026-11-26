import { test, expect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';

const manager = roles.find((role) => role.key === 'manager');
const cashier = roles.find((role) => role.key === 'cashier');

// REQ-003 AC-09: a pay-later order with a flagged line has its kitchen item from the accept; paying at the counter
// adds no second item, and once it is paid and handed over the order completes. The staff steps are the ones on the
// kitchen display (Mulai buat, Siap, Serahkan); the Orders queue only shows the order go away.
test.describe('38-dine-orders-queue', () => {
    test.describe.configure({ timeout: 240_000 });

    test('a flagged pay-later order is paid at the counter and then made and handed over: one kitchen item throughout, then completed', { tag: ['@REQ-003-AC-09'] }, async ({ asRole, page: guest, arrange, uniq }) => {
        const { table_name, token } = arrange('dine.table', { area: uniq('Area'), name: uniq('M') });
        const title = uniq('Pecel');
        const { product_id } = arrange('product', { title, stock: 5 });
        arrange('kitchen.flag', { product_id });
        const { access_token } = arrange('dine.order', { token, product_id, qty: 1, option: 'pay_later' });
        arrange('open-shift', { cashier: manager.email, cash: 100000 });

        const page = await asRole(manager);
        await page.goto('/dashboard/dine-orders');
        const card = page.locator('div.rounded-2xl', { hasText: table_name });
        page.once('dialog', (confirm) => confirm.accept());
        await card.getByRole('button', { name: 'Terima' }).click();
        await expect(card).toContainText('Belum Lunas');
        const accepted = arrange('dine.order-state', { access_token, product_id });
        expect(accepted).toMatchObject({ status: 'accepted', payment_status: 'unpaid', kitchen_items: 1, receivables: 0 });

        // Paid at the counter from the POS strip: the same transaction, and still one kitchen item.
        await page.goto('/dashboard/transactions');
        await page.getByRole('button', { name: /Belum Lunas/ }).click();
        const bill = page.getByTestId('open-bills').getByRole('listitem').filter({ hasText: table_name });
        await bill.getByRole('button', { name: 'Bayar' }).click();
        const dialog = page.getByRole('dialog');
        await dialog.getByLabel('Uang diterima').fill('10000');
        await dialog.getByRole('button', { name: 'Bayar', exact: true }).click();
        await page.waitForURL(new RegExp(`/transactions/${accepted.invoice}/print`));
        expect(arrange('dine.order-state', { access_token })).toMatchObject({
            status: 'accepted', invoice: accepted.invoice, payment_status: 'paid', served: false, kitchen_items: 1, receivables: 0,
        });

        // Made and handed over on the kitchen display (it owns Mulai buat, Siap and Serahkan since #326 and #238): the order has a
        // kitchen item, so the Orders page has no Serahkan for it. Paid and served, so the order completes.
        const kitchen = await asRole(cashier);
        await kitchen.goto('/dashboard/kitchen');
        const item = kitchen.locator('article', { hasText: table_name }).getByRole('listitem').filter({ hasText: title });
        await item.getByRole('button', { name: 'Mulai buat', exact: true }).click();
        await expect(item).toContainText('Sedang dibuat');
        await item.getByRole('button', { name: 'Siap', exact: true }).click();
        await expect(item).toContainText('Siap');

        await page.goto('/dashboard/dine-orders');
        await expect(card.getByRole('button', { name: 'Serahkan' })).toHaveCount(0);
        await item.getByRole('button', { name: 'Serahkan', exact: true }).click();
        await expect(kitchen.locator('article', { hasText: table_name })).toHaveCount(0);
        await page.reload();
        await expect(card).toHaveCount(0);

        expect(arrange('dine.order-state', { access_token })).toMatchObject({ status: 'completed', served: true, payment_status: 'paid', kitchen_items: 1 });
        await guest.goto(`/dine-order/${access_token}`);
        await expect(guest.getByTestId('dine-status')).toHaveText('Selesai');
    });
});
