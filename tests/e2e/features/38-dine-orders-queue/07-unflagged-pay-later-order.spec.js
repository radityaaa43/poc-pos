import { test, expect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';

const manager = roles.find((role) => role.key === 'manager');

// REQ-003 AC-09: a pay-later order with nothing to cook (no line needs preparation) has nothing for the kitchen, so it
// is served the moment staff accept it, and completes once the guest pays at the counter. The Orders queue lists only
// orders still to cook or hand over, so a served order is read from the guest page, the POS strip and the stored state.
test.describe('38-dine-orders-queue', () => {
    test.describe.configure({ timeout: 240_000 });

    test('an all-unflagged pay-later order is served at accept and completes when the guest pays at the counter', { tag: ['@REQ-003-AC-09'] }, async ({ asRole, page: guest, arrange, uniq }) => {
        const { table_name, token } = arrange('dine.table', { area: uniq('Area'), name: uniq('M') });
        const { product_id } = arrange('product', { title: uniq('Kerupuk'), stock: 5 }); // not flagged: needs no preparation
        const { access_token } = arrange('dine.order', { token, product_id, qty: 2, option: 'pay_later' });
        arrange('open-shift', { cashier: manager.email, cash: 100000 });

        const page = await asRole(manager);
        await page.goto('/dashboard/dine-orders');
        const card = page.locator('div.rounded-2xl', { hasText: table_name });
        page.once('dialog', (confirm) => confirm.accept());
        await card.getByRole('button', { name: 'Terima' }).click();

        // Served and still unpaid; nothing went to the kitchen, nothing is owed on credit.
        await expect.poll(() => arrange('dine.order-state', { access_token }).status, { timeout: 30_000 }).toBe('served');
        const served = arrange('dine.order-state', { access_token, product_id });
        expect(served).toMatchObject({ status: 'served', payment_status: 'unpaid', kitchen_items: 0, receivables: 0, served: true, product_stock: 3 });
        await guest.goto(`/dine-order/${access_token}`);
        await expect(guest.getByTestId('dine-status')).toHaveText('Disajikan');

        // The unpaid bill is on the POS strip as already handed over; the cashier settles it in cash.
        await page.goto('/dashboard/transactions');
        await page.getByRole('button', { name: /Belum Lunas/ }).click();
        const bill = page.getByTestId('open-bills').getByRole('listitem').filter({ hasText: table_name });
        await expect(bill).toContainText('Sudah diserahkan');
        await bill.getByRole('button', { name: 'Bayar' }).click();
        const dialog = page.getByRole('dialog');
        await dialog.getByLabel('Uang diterima').fill('20000');
        await dialog.getByRole('button', { name: 'Bayar', exact: true }).click();
        await page.waitForURL(new RegExp(`/transactions/${served.invoice}/print`));

        // Served and paid: the paid bill completes the order. Still no kitchen item.
        expect(arrange('dine.order-state', { access_token })).toMatchObject({ status: 'completed', payment_status: 'paid', kitchen_items: 0, receivables: 0 });
        await guest.reload();
        await expect(guest.getByTestId('dine-status')).toHaveText('Selesai');
    });
});
