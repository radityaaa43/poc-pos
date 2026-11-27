import { test, expect as baseExpect } from '../../support/fixtures.js';
import { signIn } from '../../support/platform.js';
import { guestStartsPaying, midtransNotification } from '../../support/dine-bill.js';

// Pages are slow when several agents share one machine, and these steps chain pages: wait longer than the 15 s default.
const expect = baseExpect.configure({ timeout: 30_000 });

const AFTER_CLOSE = 'Dibayar setelah shift ditutup';

const settle = async (request, bill) =>
    (await request.post('/api/webhooks/midtrans', { data: midtransNotification(bill, `${bill.invoice}-r1`, 'settlement') })).status();
// "Penjualan Non Tunai" of the shift page: the stored value once closed, the live one while open.
const nonCash = (page) => page.getByText('Penjualan Non Tunai', { exact: true }).locator('xpath=following-sibling::p');

// REQ-005 J5.2: the guest pays after the shift that accepted the order closed. The closed recap never changes; the sale is
// flagged and gets its own line in the sales report. While the shift is still open the payment is in its non-cash recap.
test.describe('80-dine-online-payment', () => {
    test.describe.configure({ timeout: 180_000 }); // several pages and a 10 s poll per step
    test.use({ actionTimeout: 30_000 });

    test('paid after the shift closed: the closed recap is unchanged, the sale is flagged in History and has its own line in the sales report', { tag: ['@REQ-005-AC-08'] }, async ({ arrange, page, request }) => {
        const bill = await guestStartsPaying(page, arrange, { staff: '1' });
        await signIn(page, bill.cashier_email);

        // The cashier closes her shift in the app while the guest's charge is pending: the snapshot holds no online money.
        await page.goto(`/dashboard/cashier-shifts/${bill.shift_id}`);
        await page.getByRole('spinbutton').fill('100000');
        await page.getByRole('button', { name: 'Finalisasi Closing' }).click();
        await expect(page.getByText('Shift Closed', { exact: true })).toBeVisible();
        await expect(nonCash(page)).toHaveText(/Rp\s*0$/);

        expect(await settle(request, bill)).toBe(200);
        const state = arrange('dine.order-state', { access_token: bill.access_token });
        expect(state).toMatchObject({ payment_status: 'paid', shift_id: bill.shift_id });
        expect(state.paid_after_close_at).not.toBeNull();
        expect(state.audit['payment.paid_after_close']).toBe(1);

        // The closed shift reads the same after the payment.
        await page.goto(`/dashboard/cashier-shifts/${bill.shift_id}`);
        await expect(page.getByText('Shift Closed', { exact: true })).toBeVisible();
        await expect(nonCash(page)).toHaveText(/Rp\s*0$/);

        // History: the badge on the row, and the chip lists the flagged sales.
        await page.goto(`/dashboard/transactions/history?invoice=${bill.invoice}`);
        await expect(page.getByRole('row').filter({ hasText: bill.invoice })).toContainText(AFTER_CLOSE);
        await page.goto('/dashboard/transactions/history');
        await page.getByRole('button', { name: AFTER_CLOSE }).click();
        await expect(page).toHaveURL(/paid_after_close=1/);
        await expect(page.getByRole('button', { name: AFTER_CLOSE })).toHaveAttribute('aria-pressed', 'true');
        await expect(page.getByRole('row').filter({ hasText: bill.invoice })).toBeVisible();

        // Sales report: its own line for the period, counted inside the totals. This cashier's outlet has no other sale.
        await page.goto('/dashboard/reports/sales');
        const line = page.getByTestId('paid-after-close-line');
        await expect(line).toContainText(AFTER_CLOSE);
        await expect(line).toContainText('1 transaksi');
        await expect(line).toContainText(/Rp\s*20\.000/);
        await expect(page.getByRole('row').filter({ hasText: bill.invoice })).toContainText(AFTER_CLOSE);
    });

    test('paid while the shift is still open: it goes into that shift\'s non-cash recap and is not flagged', { tag: ['@REQ-005-AC-08'] }, async ({ arrange, page, request }) => {
        const bill = await guestStartsPaying(page, arrange, { staff: '1' });
        await signIn(page, bill.cashier_email);
        await page.goto(`/dashboard/cashier-shifts/${bill.shift_id}`);
        await expect(page.getByText('Shift Aktif', { exact: true })).toBeVisible();
        await expect(nonCash(page)).toHaveText(/Rp\s*0$/);

        expect(await settle(request, bill)).toBe(200);

        await page.reload();
        await expect(nonCash(page)).toHaveText(/Rp\s*20\.000$/);
        const state = arrange('dine.order-state', { access_token: bill.access_token });
        expect(state).toMatchObject({ payment_status: 'paid', paid_after_close_at: null, shift_id: bill.shift_id });
        expect(state.audit['payment.paid_after_close'] ?? 0).toBe(0);

        // Nothing is flagged: no row carries the badge and the report has no such line.
        await page.goto(`/dashboard/transactions/history?invoice=${bill.invoice}`);
        await expect(page.getByRole('row').filter({ hasText: bill.invoice })).not.toContainText(AFTER_CLOSE);
        await page.goto('/dashboard/reports/sales');
        await expect(page.getByRole('row').filter({ hasText: bill.invoice })).toBeVisible();
        await expect(page.getByTestId('paid-after-close-line')).toHaveCount(0);
    });
});
