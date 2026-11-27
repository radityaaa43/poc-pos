import { test, expect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';

const cashier = roles.find((role) => role.key === 'cashier');
const manager = roles.find((role) => role.key === 'manager');

// REQ-003 AC-27: the shift that accepts a pay-later bill holds it; whichever cashier settles it, the money lands in
// the shift of the cashier who took it. Cashier A accepts in A's shift, cashier B (the manager, in B's own shift)
// settles from the POS strip, and the bill leaves everyone's list. A second bill that stays open keeps the strip on
// screen, so "the bill is gone" is a check on a list that is really there.
test.describe('38-dine-orders-queue', () => {
    test.describe.configure({ timeout: 240_000 });

    test('a pay-later bill accepted in one shift is settled in another shift: the payment moves to that shift and the bill leaves the list', { tag: ['@REQ-003-AC-27'] }, async ({ asRole, arrange, uniq }) => {
        const bill = arrange('dine.table', { area: uniq('Area'), name: uniq('M') });
        const control = arrange('dine.table', { area: uniq('Area'), name: uniq('M') });
        const { product_id } = arrange('product', { title: uniq('Sate'), stock: 10 });
        const order = arrange('dine.order', { token: bill.token, product_id, qty: 1, option: 'pay_later' });
        arrange('dine.order', { token: control.token, product_id, qty: 1, option: 'pay_later' });
        const shiftA = arrange('open-shift', { cashier: cashier.email, cash: 100000 }).shift_id;
        const shiftB = arrange('open-shift', { cashier: manager.email, cash: 100000 }).shift_id;
        expect(shiftA).not.toBe(shiftB);

        // A accepts both orders in A's shift.
        const pageA = await asRole(cashier);
        await pageA.goto('/dashboard/dine-orders');
        for (const table of [bill, control]) {
            const card = pageA.locator('div.rounded-2xl', { hasText: table.table_name });
            pageA.once('dialog', (confirm) => confirm.accept());
            await card.getByRole('button', { name: 'Terima' }).click();
            await expect(card.getByRole('button', { name: 'Terima' })).toHaveCount(0);
        }

        const accepted = arrange('dine.order-state', { access_token: order.access_token });
        expect(arrange('paylater.sale-state', { invoice: accepted.invoice })).toMatchObject({ payment_status: 'unpaid', cashier_shift_id: shiftA });

        // B sees A's bill on the POS strip and settles it in cash.
        const pageB = await asRole(manager);
        await pageB.goto('/dashboard/transactions');
        await pageB.getByRole('button', { name: /Belum Lunas/ }).click();
        const billB = pageB.getByTestId('open-bills').getByRole('listitem').filter({ hasText: bill.table_name });
        await expect(billB).toHaveCount(1);
        await billB.getByRole('button', { name: 'Bayar' }).click();
        const dialog = pageB.getByRole('dialog');
        await dialog.getByLabel('Uang diterima').fill('10000');
        await dialog.getByRole('button', { name: 'Bayar', exact: true }).click();
        await pageB.waitForURL(new RegExp(`/transactions/${accepted.invoice}/print`));

        // The payment belongs to the shift that took it, not to A's.
        expect(arrange('paylater.sale-state', { invoice: accepted.invoice })).toMatchObject({
            payment_status: 'paid', cashier_shift_id: shiftB, receivables: 0,
        });

        // A's list no longer has the bill; the other one is still there.
        await pageA.goto('/dashboard/transactions');
        await pageA.getByRole('button', { name: /Belum Lunas/ }).click();
        const listA = pageA.getByTestId('open-bills').getByRole('listitem');
        await expect(listA.filter({ hasText: control.table_name })).toHaveCount(1);
        await expect(listA.filter({ hasText: bill.table_name })).toHaveCount(0);
    });
});
