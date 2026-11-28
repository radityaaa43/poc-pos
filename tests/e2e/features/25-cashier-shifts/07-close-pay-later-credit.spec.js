import { cashier, closeRefused, closeUnheld, describeShiftClose, expect, test } from '../../support/shift-close.js';

describeShiftClose(() => {
    test('a credit customer\'s unpaid pay later does not hold the close, a guest\'s does', { tag: ['@REQ-003-AC-17'] }, async ({ asRole, arrange, uniq }) => {
        // A customer's pay-later sale leaves a receivable: it is collected through Piutang, not by this shift.
        arrange('open-shift', { cash: 100000 });
        const credit = arrange('posops.sale', { method: 'pay_later', qty: 1, left: 9, title: uniq('Kredit') });
        const { shift_id } = arrange('shiftclose.state', { invoice: credit.invoice });
        const page = await asRole(cashier);
        await closeUnheld(page, shift_id);
        expect(arrange('shiftclose.state', { shift_id })).toMatchObject({ status: 'closed', unpaid_bills_total: 0, cash_difference: 0 });
        expect(arrange('paylater.sale-state', { invoice: credit.invoice })).toMatchObject({ payment_status: 'unpaid', receivables: 1 });
        // A guest's pay-later sale has no receivable: the next shift cannot close over it.
        const { shift_id: nextShift } = arrange('open-shift', { cash: 100000 });
        const guest = arrange('posops.sale', { guest: 1, qty: 1, left: 9, title: uniq('Tamu') });
        await page.goto(`/dashboard/cashier-shifts/${nextShift}`);
        const rows = page.getByRole('region', { name: 'Belum selesai' }).getByRole('listitem');
        await expect(rows).toHaveCount(1);
        await expect(rows).toContainText(guest.invoice);
        await expect(rows).toContainText('Belum lunas');
        await expect(rows).toContainText(/Rp\s*10\.000/);
        await closeRefused(page, '100000');
        expect(arrange('shiftclose.state', { shift_id: nextShift }).status).toBe('open');
    });
});
