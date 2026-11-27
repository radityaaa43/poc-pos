import { test, expect as baseExpect } from '../../support/fixtures.js';
import { midtransNotification } from '../../support/dine-bill.js';

// Pages are slow when several agents share one machine: wait longer than the 15 s default.
const expect = baseExpect.configure({ timeout: 30_000 });

// REQ-005 AC-04: the guest reads why the payment did not go through and tries again, for the same amount.
test.describe('80-dine-online-payment', () => {
    test.describe.configure({ timeout: 180_000 }); // three charges, each waiting for the 5 s poll
    test.use({ actionTimeout: 30_000 });

    test('a failed and an expired payment show their own text and Coba lagi charges the same amount again', { tag: ['@REQ-005-AC-04'] }, async ({ arrange, page, request }) => {
        arrange('public.reset-throttle');
        const bill = arrange('dine.bill', { gateway: 'midtrans' });
        await page.goto(`/dine-order/${bill.access_token}`);
        const panel = page.getByTestId('guest-pay');
        const press = (name) => panel.getByRole('button', { name }).click();
        // The gateway page link names the order id of the charge on screen.
        const charged = (attempt) =>
            expect(panel.getByRole('link', { name: 'Buka di aplikasi e-wallet' })).toHaveAttribute('href', new RegExp(`${bill.invoice}-r${attempt}$`));
        const notify = async (attempt, status) => {
            const data = midtransNotification(bill, `${bill.invoice}-r${attempt}`, status);
            expect((await request.post('/api/webhooks/midtrans', { data })).status()).toBe(200);
        };
        const shows = async (title) => {
            await expect(panel.getByRole('heading', { name: title })).toBeVisible();
            await expect(panel).toContainText(/Rp\s*20\.000/);
            await expect(panel.getByText('Atau bayar di kasir')).toBeVisible();
            await expect(panel.getByRole('img', { name: 'QRIS' })).toHaveCount(0);
        };

        await press('Bayar sekarang');
        await charged(1);
        await notify(1, 'deny');
        await shows('Pembayaran gagal');

        await press('Coba lagi');
        await charged(2);
        await notify(2, 'expire');
        await shows('Kedaluwarsa');

        await press('Coba lagi');
        await charged(3);
        await expect(panel.getByRole('img', { name: 'QRIS' })).toBeVisible();
        await expect(panel).toContainText(/Rp\s*20\.000/);
        expect(arrange('dine.order-state', { access_token: bill.access_token })).toMatchObject({
            payment_status: 'pending', payment_attempt: 3, payment_failure_reason: null, grand_total: bill.grand_total,
        });

        await notify(3, 'settlement');
        await expect(panel.getByRole('heading', { name: 'Lunas' })).toBeVisible();
    });
});
