import { cashier, closeUnheld, describeShiftClose, expect, test } from '../../support/shift-close.js';

describeShiftClose(() => {
    test('closing cancels the unpaid Pay now sale, puts its stock back and audits it; the paid sale is left alone', { tag: ['@REQ-003-AC-20'] }, async ({ asRole, arrange }) => {
        const { shift_id } = arrange('open-shift', { cash: 100000 });
        // Two QRIS sales in that shift, their stock already taken: one still waiting (10 - 2), one paid (10 - 1).
        const waiting = arrange('posretry.failed-sale', { status: 'pending', qty: 2 });
        const paid = arrange('posretry.failed-sale', { status: 'paid', qty: 1 });
        expect(arrange('shiftclose.sale-state', { invoice: waiting.invoice })).toMatchObject({ payment_status: 'pending', product_stock: 8, warehouse_stock: 8, restocks: 0 });
        const page = await asRole(cashier);
        // An unpaid Pay now sale does not hold the close.
        await closeUnheld(page, shift_id);
        await expect(page.getByText('Penjualan Non Tunai', { exact: true }).locator('xpath=following-sibling::p')).toHaveText(/Rp\s*10\.000$/);
        const cancelled = arrange('shiftclose.sale-state', { invoice: waiting.invoice });
        expect(cancelled).toMatchObject({ payment_status: 'failed', product_stock: 10, warehouse_stock: 10, restocks: 1 });
        expect(cancelled.audit['transaction.cancelled_on_shift_close']).toBe(1);
        expect(arrange('shiftclose.sale-state', { invoice: paid.invoice })).toMatchObject({ payment_status: 'paid', product_stock: 9, warehouse_stock: 9, restocks: 0 });
        expect(arrange('shiftclose.state', { shift_id })).toMatchObject({ status: 'closed', transactions_count: 1, non_cash_sales_total: 10000, cash_difference: 0 });
    });
});
