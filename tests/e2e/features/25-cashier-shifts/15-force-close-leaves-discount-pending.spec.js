import { confirmPassword, signIn } from '../../support/platform.js';
import { describeShiftClose, expect, test } from '../../support/shift-close.js';

// #542 BR-3: a force close over a pending discount leaves the sale and its request pending; the later denial is
// collected with Bayar selisih in the paying shift (#509's e2e) and never changes the closed recap.
describeShiftClose(() => {
    test('#542 BR-3: a force close leaves the pending discount pending, and a later denial in the approvals page keeps the recap', async ({ page, arrange, uniq }) => {
        const { shift_id } = arrange('open-shift', { cash: 100000 });
        const pending = arrange('posops.pending-discount', { shift_id, total: 20000, discount: 5000, note: uniq('Meja') });
        // A fresh login, so the password confirmation never leaks into the manager's shared saved session.
        await signIn(page, 'manager@e2e.test');
        await confirmPassword(page);
        await page.goto(`/dashboard/cashier-shifts/${shift_id}`);
        const rows = page.getByRole('region', { name: 'Belum selesai' }).getByRole('listitem');
        await expect(rows).toHaveCount(1);
        await expect(rows).toContainText(/Diskon menunggu persetujuan — Rp\s*5\.000/);
        await expect(page.getByText('Diskon yang masih menunggu persetujuan tetap berstatus menunggu.')).toBeVisible();

        await page.getByRole('spinbutton').fill('115000');
        await page.getByLabel('Catatan tutup paksa').fill('Diskon belum diputus');
        await page.getByRole('button', { name: 'Tutup paksa' }).click();
        await expect(page.getByText('Force Closed', { exact: true })).toBeVisible();

        // BR-3: nothing is cancelled or booked as a shortfall, the cash taken at checkout stays in the recap, and the
        // audit names the invoice and its discount.
        expect(arrange('shiftclose.sale-state', { invoice: pending.invoice })).toMatchObject({
            payment_status: 'pending_approval',
            discount_approval_status: 'pending',
            restocks: 0,
        });
        const closed = arrange('shiftclose.state', { shift_id });
        expect(closed).toMatchObject({
            status: 'force_closed',
            cash_sales_total: 15000,
            expected_cash: 115000,
            cash_difference: 0,
            transactions_count: 1,
            override: { pending_discounts: [{ invoice: pending.invoice, discount: 5000 }], cancelled_pay_now: [] },
        });

        // The sale is still on the approvals page: deny it there and the stored recap must stay exactly as it closed.
        await page.goto('/dashboard/discount-approvals');
        const card = page.locator('div').filter({ hasText: pending.invoice }).filter({ has: page.getByRole('button', { name: 'Tolak' }) }).last();
        await expect(card).toContainText(/Diskon:\s*Rp\s*5\.000/);
        page.once('dialog', (dialog) => dialog.accept());
        await card.getByRole('button', { name: 'Tolak' }).click();
        await expect(page.getByText(pending.invoice)).toHaveCount(0);

        expect(arrange('shiftclose.state', { shift_id })).toMatchObject({
            status: 'force_closed',
            cash_sales_total: 15000,
            expected_cash: 115000,
            cash_difference: 0,
            transactions_count: 1,
        });
    });
});
