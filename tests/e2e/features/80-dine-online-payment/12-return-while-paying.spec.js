import { test, expect as baseExpect } from '../../support/fixtures.js';
import { signIn } from '../../support/platform.js';
import { guestStartsPaying, midtransNotification } from '../../support/dine-bill.js';

// Pages are slow when several agents share one machine, and this step chains pages: wait longer than the 15 s default.
const expect = baseExpect.configure({ timeout: 30_000 });

const preview = (page, label) => page.getByText(label, { exact: true }).locator('xpath=following-sibling::span');

// Issue #444: a return completed while the guest is paying lowers what the bill owes, the old total still arrives.
test.describe('80-dine-online-payment', () => {
    test.describe.configure({ timeout: 180_000 });
    test.use({ actionTimeout: 30_000 });

    test('an old total paid after a return is flagged for a refund and the shift counts only what was owed', { tag: ['@BR-1', '@BR-2'] }, async ({ arrange, page, request }) => {
        // 2 x 10000, and the guest is paying 20000.
        const bill = await guestStartsPaying(page, arrange, { staff: '1', returns: '1' });
        await signIn(page, bill.cashier_email);

        await page.goto(`/dashboard/transactions/history?invoice=${bill.invoice}`);
        await page.getByRole('link', { name: 'Retur', exact: true }).click();
        await page.getByRole('spinbutton').fill('1');
        await page.getByPlaceholder('Alasan retur').fill('Batal pesan');
        await expect(preview(page, 'Refund')).toHaveText(/Rp\s*0/);
        await page.getByRole('button', { name: 'Buat Draft' }).click();
        await page.getByRole('button', { name: 'Selesaikan Retur' }).click();
        // Navigating away before the completion lands aborts it.
        await expect(page.getByText('Completed', { exact: true })).toBeVisible();

        const settle = async () => (await request.post('/api/webhooks/midtrans', { data: midtransNotification(bill, `${bill.invoice}-r1`, 'settlement') })).status();
        expect(await settle()).toBe(200);
        expect(await settle()).toBe(200); // a redelivery adds nothing

        const state = arrange('dine.order-state', { access_token: bill.access_token });
        expect(state).toMatchObject({ payment_status: 'paid', overpaid_amount: 10000 });
        expect(state.late_superseded_payment_at).not.toBeNull();
        expect(state.audit['payment.overpaid_after_return']).toBe(1);

        await page.goto(`/dashboard/transactions/history?invoice=${bill.invoice}`);
        await expect(page.getByRole('row').filter({ hasText: bill.invoice })).toContainText('Bayar ganda — refund manual');
        await page.goto(`/dashboard/transactions/${bill.invoice}/print`);
        await expect(page.getByRole('status').filter({ hasText: 'Kelebihan bayar' })).toContainText(/Rp\s*10\.000 setelah retur — refund manual\./);

        await page.goto(`/dashboard/cashier-shifts/${bill.shift_id}`);
        await expect(page.getByText('Penjualan Non Tunai', { exact: true }).locator('xpath=following-sibling::p')).toHaveText(/^Rp\s*10\.000$/);
    });
});
