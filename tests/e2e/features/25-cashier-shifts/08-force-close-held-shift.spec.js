import { confirmPassword, signIn } from '../../support/platform.js';
import { describeShiftClose, expect, test } from '../../support/shift-close.js';

describeShiftClose(() => {
    test('a manager force closes a held shift with a note: food is not collected and the unpaid bill goes into the cash difference', { tag: ['@REQ-003-AC-13'] }, async ({ page, arrange, uniq }) => {
        // The cashier's shift: food not handed over (paid 10.000 cash), a guest's unpaid 20.000 bill and a paid 10.000 QRIS sale.
        const food = arrange('kitchen-sale', { prefix: uniq('Paksa') });
        const bill = arrange('posops.sale', { guest: 1, qty: 2, left: 8, title: uniq('Utang') });
        arrange('posretry.failed-sale', { status: 'paid', qty: 1 });
        const { shift_id } = arrange('shiftclose.state', { invoice: food.invoice });
        // A fresh login, so the password confirmation never leaks into the manager's shared saved session (05 covers the step-up itself).
        await signIn(page, 'manager@e2e.test');
        await confirmPassword(page);
        await page.goto(`/dashboard/cashier-shifts/${shift_id}`);
        const rows = page.getByRole('region', { name: 'Belum selesai' }).getByRole('listitem');
        await expect(rows).toHaveCount(2);
        await expect(rows.filter({ hasText: food.invoice })).toContainText('Belum diserahkan');
        await expect(rows.filter({ hasText: bill.invoice })).toContainText('Belum lunas');
        await expect(rows.filter({ hasText: bill.invoice })).toContainText(/Rp\s*20\.000/);
        await expect(page.getByText('Item yang belum diserahkan ditandai tidak diambil, dan tagihan belum lunas masuk selisih kas.')).toBeVisible();
        await page.getByRole('spinbutton').fill('110000');
        // Without a note the form is not sent.
        const note = page.getByLabel('Catatan tutup paksa');
        await page.getByRole('button', { name: 'Tutup paksa' }).click();
        expect(await note.evaluate((field) => field.validity.valueMissing)).toBe(true);
        expect(arrange('shiftclose.state', { shift_id }).status).toBe('open');
        await note.fill('Tamu sudah pulang');
        await page.getByRole('button', { name: 'Tutup paksa' }).click();
        await expect(page.getByText('Force Closed', { exact: true })).toBeVisible();
        await expect(page.getByText('Tagihan belum lunas dibukukan', { exact: true }).locator('xpath=following-sibling::span')).toHaveText(/Rp\s*20\.000/);
        await expect(page.getByText('Selisih', { exact: true }).locator('xpath=following-sibling::span')).toHaveText(/-Rp\s*20\.000/);
        // Only the money the bank received: the QRIS sale, not the booked bill.
        await expect(page.getByText('Penjualan Non Tunai', { exact: true }).locator('xpath=following-sibling::p')).toHaveText(/Rp\s*10\.000$/);
        const state = arrange('shiftclose.state', { shift_id });
        expect(state).toMatchObject({
            status: 'force_closed', close_notes: 'Tamu sudah pulang', non_cash_sales_total: 10000,
            expected_cash: 110000, unpaid_bills_total: 20000, cash_difference: -20000,
            override: { note: 'Tamu sudah pulang', unpaid_bills: [bill.invoice], unpaid_bills_total: 20000, cancelled_pay_now: [] },
        });
        expect(state.override.not_collected_item_ids).toHaveLength(1);
        expect(state.audit['cashier_shift.force_closed']).toBe(1);
        expect(state.audit['cashier_shift.hold_overridden']).toBe(1);
        expect(arrange('kitchen.items', { invoice: food.invoice }).statuses).toEqual(['not_collected']);
        // The bill stays payable: booking it as a shortfall does not mark it paid.
        expect(arrange('paylater.sale-state', { invoice: bill.invoice })).toMatchObject({ payment_status: 'unpaid', receivables: 0 });
    });
});
