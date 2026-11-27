import { test, expect as baseExpect } from '../../support/fixtures.js';
import { midtransNotification } from '../../support/dine-bill.js';

// Pages are slow when several agents share one machine: wait longer than the 15 s default.
const expect = baseExpect.configure({ timeout: 30_000 });

// REQ-005 AC-03: the gateway confirms, and the page the guest keeps open shows Lunas by itself.
test.describe('80-dine-online-payment', () => {
    test.describe.configure({ timeout: 120_000 });
    // Tall enough that nothing scrolls: Inertia records a scroll with history.replaceState, which counts as a navigation.
    test.use({ actionTimeout: 30_000, viewport: { width: 1280, height: 2000 } });

    test('Lunas appears without a reload, the items go to the kitchen and the bill leaves Belum Lunas', { tag: ['@REQ-005-AC-03'] }, async ({ arrange, page, request }) => {
        arrange('public.reset-throttle');
        const bill = arrange('dine.bill', { gateway: 'midtrans', needs_prep: '1' });
        await page.goto(`/dine-order/${bill.access_token}`);
        const panel = page.getByTestId('guest-pay');
        await panel.getByRole('button', { name: 'Bayar sekarang' }).click();

        await expect(panel.getByRole('heading', { name: 'Menunggu pembayaran' })).toBeVisible();
        const qr = panel.getByRole('img', { name: 'QRIS' });
        await expect(qr).toHaveAttribute('src', /^data:image\/svg\+xml;base64,/);
        const link = panel.getByRole('link', { name: 'Buka di aplikasi e-wallet' });
        await expect(link).toHaveAttribute('href', new RegExp(`${bill.invoice}-r1$`));
        await expect(link).toHaveAttribute('target', '_blank');
        await expect(link).toHaveAttribute('rel', 'noopener noreferrer');

        // Counted from here: nothing below may load or navigate the page again.
        let navigations = 0;
        page.on('framenavigated', (frame) => {
            if (frame === page.mainFrame()) navigations += 1;
        });

        // The 5 s poll carries no QR: after two of them it is still on screen.
        const poll = () => page.waitForResponse((response) => response.url().endsWith(`/dine-order/${bill.access_token}/check`));
        await poll();
        await poll();
        await expect(qr).toBeVisible();

        const settle = async () =>
            (await request.post('/api/webhooks/midtrans', { data: midtransNotification(bill, `${bill.invoice}-r1`, 'settlement') })).status();
        expect(await settle()).toBe(200);
        // REQ-005: Lunas at most 10 seconds after the gateway confirms.
        await expect(panel.getByRole('heading', { name: 'Lunas' })).toBeVisible({ timeout: 10_000 });
        await expect(qr).toHaveCount(0);
        await expect(panel.getByRole('button')).toHaveCount(0);
        expect(navigations).toBe(0);

        const paid = { payment_status: 'paid', payment_attempt: 1, kitchen_items: 1, in_open_bills: false };
        expect(arrange('dine.order-state', { access_token: bill.access_token })).toMatchObject(paid);
        // The gateway repeats itself: paid once, in the kitchen once.
        expect(await settle()).toBe(200);
        expect(arrange('dine.order-state', { access_token: bill.access_token })).toMatchObject(paid);
    });
});
