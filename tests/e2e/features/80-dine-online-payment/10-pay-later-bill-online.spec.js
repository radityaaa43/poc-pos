import { test, expect as baseExpect } from '../../support/fixtures.js';
import { midtransNotification } from '../../support/dine-bill.js';

// Pages are slow when several agents share one machine: wait longer than the 15 s default.
const expect = baseExpect.configure({ timeout: 30_000 });

// REQ-005 AC-05: a Bayar Belakangan bill, already in the kitchen, is paid from the guest's phone and not made twice.
test.describe('80-dine-online-payment', () => {
    test.describe.configure({ timeout: 180_000 }); // two charges, each waiting for the 5 s poll
    // Tall enough that nothing scrolls: Inertia records a scroll with history.replaceState, which counts as a navigation.
    test.use({ actionTimeout: 30_000, viewport: { width: 1280, height: 2000 } });

    test('a pay-later bill paid online is settled, leaves Belum Lunas and reaches the kitchen only once', { tag: ['@REQ-005-AC-05'] }, async ({ arrange, page, request }) => {
        arrange('public.reset-throttle');
        const bill = arrange('dine.bill', { gateway: 'midtrans', needs_prep: '1', pay_later: '1' });
        const state = () => arrange('dine.order-state', { access_token: bill.access_token });
        expect(state()).toMatchObject({ is_pay_later: true, payment_status: 'unpaid', kitchen_items: 1, receivables: 0, in_open_bills: true });

        await page.goto(`/dine-order/${bill.access_token}`);
        const panel = page.getByTestId('guest-pay');
        const unpaid = panel.getByText('Belum lunas', { exact: true });
        await expect(unpaid).toBeVisible();
        await expect(page.getByText('Pesanan diterima. Belum lunas: bayar sekarang atau di kasir sebelum Anda pergi.')).toBeVisible();
        await expect(page.getByText(/Pembayaran diterima|Silakan menuju kasir/)).toHaveCount(0);

        // Counted from here: nothing below may load or navigate the page again.
        let navigations = 0;
        page.on('framenavigated', (frame) => {
            if (frame === page.mainFrame()) navigations += 1;
        });
        const charged = (attempt) =>
            expect(panel.getByRole('link', { name: 'Buka di aplikasi e-wallet' })).toHaveAttribute('href', new RegExp(`${bill.invoice}-r${attempt}$`));
        const notify = async (attempt, status) => {
            const data = midtransNotification(bill, `${bill.invoice}-r${attempt}`, status);
            expect((await request.post('/api/webhooks/midtrans', { data })).status()).toBe(200);
        };

        await panel.getByRole('button', { name: 'Bayar sekarang' }).click();
        await charged(1);
        await notify(1, 'deny');
        await expect(panel.getByRole('heading', { name: 'Pembayaran gagal' })).toBeVisible();
        await expect(unpaid).toBeVisible();

        await panel.getByRole('button', { name: 'Coba lagi' }).click();
        await charged(2);
        await expect(panel).toContainText(/Rp\s*20\.000/);

        await notify(2, 'settlement');
        // REQ-005: Lunas at most 10 seconds after the gateway confirms.
        await expect(panel.getByRole('heading', { name: 'Lunas' })).toBeVisible({ timeout: 10_000 });
        await expect(unpaid).toHaveCount(0);
        await expect(panel.getByRole('button')).toHaveCount(0);
        expect(navigations).toBe(0);

        const paid = { payment_status: 'paid', payment_attempt: 2, kitchen_items: 1, receivables: 0, in_open_bills: false };
        expect(state()).toMatchObject(paid);
        // The gateway repeats itself: paid once, in the kitchen once.
        await notify(2, 'settlement');
        expect(state()).toMatchObject({ ...paid, late_superseded_payment_at: null });
    });

    test('a pay-later bill at an outlet without a gateway reads Belum lunas and Silakan bayar di kasir', { tag: ['@REQ-005-AC-05'] }, async ({ arrange, page }) => {
        // needs_prep: with nothing to make the order would be served at accept, and the accepted banner would not show.
        const bill = arrange('dine.bill', { gateway: 'none', needs_prep: '1', pay_later: '1' });
        await page.goto(`/dine-order/${bill.access_token}`);

        const panel = page.getByTestId('guest-pay');
        await expect(panel.getByRole('heading', { name: 'Silakan bayar di kasir' })).toBeVisible();
        await expect(panel.getByText('Belum lunas', { exact: true })).toBeVisible();
        await expect(page.getByText('Pesanan diterima. Belum lunas: bayar di kasir sebelum Anda pergi.')).toBeVisible();
        await expect(page.getByRole('button', { name: /Bayar/ })).toHaveCount(0);
    });
});
