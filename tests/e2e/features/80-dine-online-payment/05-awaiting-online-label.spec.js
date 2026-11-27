import { test, expect as baseExpect } from '../../support/fixtures.js';
import { signIn } from '../../support/platform.js';
import { guestStartsPaying, midtransNotification, postPay } from '../../support/dine-bill.js';

// Pages are slow when several agents share one machine, and these steps chain pages: wait longer than the 15 s default.
const expect = baseExpect.configure({ timeout: 30_000 });

const CHIP = 'Menunggu pembayaran online';

const openList = async (page) => {
    await page.goto('/dashboard/transactions');
    await page.getByRole('button', { name: /Belum Lunas/ }).click();
};
const billRow = (page, bill) => page.getByTestId('open-bills').getByRole('listitem').filter({ hasText: bill.invoice });
const notify = async (request, bill, status) =>
    (await request.post('/api/webhooks/midtrans', { data: midtransNotification(bill, `${bill.invoice}-r1`, status) })).status();

// REQ-005 AC-10: the cashier's POS unpaid bill (Belum Lunas) list tells her the guest is paying, so she asks before taking money.
test.describe('80-dine-online-payment', () => {
    test.describe.configure({ timeout: 180_000 }); // several pages and a 10 s poll per step
    test.use({ actionTimeout: 30_000 });

    test('a bill the guest is paying online is marked Menunggu pembayaran online, and leaves the list by itself once paid', { tag: ['@REQ-005-AC-10'] }, async ({ arrange, page, request }) => {
        const bill = await guestStartsPaying(page, arrange, { staff: '1' });
        await signIn(page, bill.cashier_email);
        await openList(page);

        const row = billRow(page, bill);
        await expect(row).toContainText(CHIP);
        await expect(row.getByRole('button', { name: 'Bayar' })).toBeVisible(); // she can still take the payment
        await expect(row.getByRole('link', { name: 'Lihat QR' })).toHaveCount(0);
        await expect(row).not.toContainText('Menunggu Pembayaran');

        // The gateway settles while the list stays open: the next poll (10 s) removes the row, no reload.
        expect(await notify(request, bill, 'settlement')).toBe(200);
        await expect(row).toHaveCount(0, { timeout: 25_000 });
    });

    test('the chip appears by itself when the guest starts paying while the list is open', { tag: ['@REQ-005-AC-10'] }, async ({ arrange, page, browser }) => {
        arrange('public.reset-throttle');
        const bill = arrange('dine.bill', { staff: '1' });
        await signIn(page, bill.cashier_email);
        await openList(page);
        const row = billRow(page, bill);
        await expect(row).toBeVisible();
        await expect(row).not.toContainText(CHIP);

        // The guest, on her own phone (another browser context), presses Bayar sekarang.
        const phone = await browser.newPage();
        try {
            await phone.goto(`/dine-order/${bill.access_token}`);
            expect((await postPay(phone, bill.access_token, { method: 'qris' })).status).toBe(200);
        } finally {
            await phone.close();
        }

        await expect(row).toContainText(CHIP, { timeout: 25_000 });
    });

    test('the cashier\'s own pending QRIS is Lihat QR with no chip', { tag: ['@REQ-005-AC-10'] }, async ({ arrange, page }) => {
        const own = arrange('dine.bill', { staff: '1' });
        arrange('dine.bill-act', { access_token: own.access_token, action: 'qris-pending' });
        expect(arrange('dine.order-state', { access_token: own.access_token })).toMatchObject({ payment_status: 'pending', payment_attempt: 0 });

        await signIn(page, own.cashier_email);
        await openList(page);

        const row = billRow(page, own);
        await expect(row.getByRole('link', { name: 'Lihat QR' })).toBeVisible();
        await expect(row).not.toContainText(CHIP);
        await expect(row.getByRole('button', { name: 'Bayar' })).toHaveCount(0);
    });

    test('a guest charge that expired is a failed bill: Bayar and no chip', { tag: ['@REQ-005-AC-10'] }, async ({ arrange, page, request }) => {
        const bill = await guestStartsPaying(page, arrange, { staff: '1' });
        expect(await notify(request, bill, 'expire')).toBe(200);

        await signIn(page, bill.cashier_email);
        await openList(page);

        const row = billRow(page, bill);
        await expect(row.getByRole('button', { name: 'Bayar' })).toBeVisible();
        await expect(row).not.toContainText(CHIP);
    });
});
