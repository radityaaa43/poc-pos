import { cashier, closeRefused, describeShiftClose, expect, test } from '../../support/shift-close.js';

describeShiftClose(() => {
    test('the close is refused with the list while food is not handed over, and the shift stays open', { tag: ['@REQ-003-AC-12'] }, async ({ asRole, arrange, uniq }) => {
        // Opens the cashier's shift and sells one product that needs preparation, paid in cash.
        const sale = arrange('kitchen-sale', { prefix: uniq('Tahan') });
        const { shift_id } = arrange('shiftclose.state', { invoice: sale.invoice });
        const page = await asRole(cashier);
        await page.goto(`/dashboard/cashier-shifts/${shift_id}`);
        const rows = page.getByRole('region', { name: 'Belum selesai' }).getByRole('listitem');
        await expect(rows).toHaveCount(1);
        await expect(rows).toContainText(sale.invoice);
        await expect(rows).toContainText(`${sale.flagged[0]} × 1`);
        await expect(rows).toContainText('Belum diserahkan');
        await expect(rows).toContainText(/Rp\s*10\.000/);
        // Without cashier-shifts-force-close there is no way to force it.
        await expect(page.getByLabel('Catatan tutup paksa')).toHaveCount(0);
        await expect(page.getByRole('button', { name: 'Tutup paksa' })).toHaveCount(0);
        await closeRefused(page, '110000');
        const state = arrange('shiftclose.state', { shift_id });
        expect(state.status).toBe('open');
        expect(state.audit['cashier_shift.closed'] ?? 0).toBe(0);
        expect(arrange('kitchen.items', { invoice: sale.invoice }).statuses).toEqual(['accepted']);
    });
});
