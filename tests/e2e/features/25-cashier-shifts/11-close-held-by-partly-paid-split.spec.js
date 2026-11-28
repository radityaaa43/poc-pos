import { cashier, closeRefused, describeShiftClose, expect, test } from '../../support/shift-close.js';

// #396 BR-1, BR-4, BR-5.
describeShiftClose(() => {
    test('a partly paid split sale holds the close and is listed with the amount still owed, in both languages', async ({ asRole, arrange }) => {
        const { shift_id } = arrange('open-shift', { cash: 100000 });
        const split = arrange('shiftclose.split-sale');
        const page = await asRole(cashier);
        await page.goto(`/dashboard/cashier-shifts/${shift_id}`);
        const rows = page.getByRole('region', { name: 'Belum selesai' }).getByRole('listitem');
        await expect(rows).toHaveCount(1);
        await expect(rows).toContainText(split.invoice);
        await expect(rows).toContainText(/Split pembayaran belum lunas — sisa Rp\s*15\.000/);
        await closeRefused(page, '105000');
        expect(arrange('shiftclose.state', { shift_id }).status).toBe('open');
        expect(arrange('shiftclose.sale-state', { invoice: split.invoice })).toMatchObject({ payment_status: 'pending', warehouse_stock: 8, restocks: 0 });

        await page.evaluate(() => localStorage.setItem('i18nextLng', 'en'));
        await page.reload();
        await expect(page.getByRole('region', { name: 'Close blocked' }).getByRole('listitem'))
            .toContainText(/Split payment not fully paid — Rp\s*15\.000 still owed/);
    });
});
