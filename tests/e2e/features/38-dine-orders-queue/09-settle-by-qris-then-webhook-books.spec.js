import { test, expect as baseExpect } from '../../support/fixtures.js';
import { signIn } from '../../support/platform.js';
import { midtransNotification } from '../../support/dine-bill.js';

// Pages are slow when several agents share one machine, and this step chains pages: wait longer than the 15 s default.
const expect = baseExpect.configure({ timeout: 30_000 });

// Issue #358: the cashier's QRIS charge is created after the payment commits and stored on the bill; the gateway's
// settlement of that attempt then books it. The e2e gateway is faked (E2eServiceProvider) and cannot fail.
test.describe('38-dine-orders-queue', () => {
    test.describe.configure({ timeout: 120_000 });
    test.use({ actionTimeout: 30_000 });

    test('the cashier pays an open bill by QRIS: it waits with a QR, and the gateway settlement books it once', async ({ arrange, page, request }) => {
        arrange('public.reset-throttle');
        const bill = arrange('dine.bill', { staff: '1', needs_prep: '1' }); // its own outlet, cashier and Midtrans setting
        const state = () => arrange('dine.order-state', { access_token: bill.access_token });
        await signIn(page, bill.cashier_email);

        await page.goto('/dashboard/transactions');
        await page.getByRole('button', { name: /Belum Lunas/ }).click();
        await page.getByTestId('open-bills').getByRole('listitem').filter({ hasText: bill.invoice }).getByRole('button', { name: 'Bayar' }).click();
        const dialog = page.getByRole('dialog');
        await dialog.getByLabel('Metode pembayaran').selectOption('qris');
        await dialog.getByRole('button', { name: 'Bayar', exact: true }).click();
        await page.waitForURL(new RegExp(`/transactions/${bill.invoice}/print`));
        await expect(page.getByText('Scan QRIS untuk membayar')).toBeVisible();

        // The faked Midtrans knows the charge by the order id it was sent.
        const orderId = `${bill.invoice}-r1`;
        expect(state()).toMatchObject({ payment_status: 'pending', payment_method: 'qris', payment_attempt: 1, payment_reference: orderId, kitchen_items: 0 });

        const settle = async () => (await request.post('/api/webhooks/midtrans', { data: midtransNotification(bill, orderId, 'settlement') })).status();
        expect(await settle()).toBe(200);
        expect(state()).toMatchObject({ payment_status: 'paid', kitchen_items: 1, late_superseded_payment_at: null });

        // The gateway sends it again: nothing changes.
        expect(await settle()).toBe(200);
        expect(state()).toMatchObject({ payment_status: 'paid', kitchen_items: 1, late_superseded_payment_at: null });
    });
});
