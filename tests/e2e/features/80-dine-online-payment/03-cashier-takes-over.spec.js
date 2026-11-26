import { test, expect as baseExpect } from '../../support/fixtures.js';
import { signIn } from '../../support/platform.js';
import { guestStartsPaying, midtransNotification } from '../../support/dine-bill.js';

// Pages are slow when several agents share one machine, and these steps chain pages: wait longer than the 15 s default.
const expect = baseExpect.configure({ timeout: 30_000 });

const LATE = 'Bayar ganda — refund manual';

const openList = async (page) => {
    await page.goto('/dashboard/transactions');
    await page.getByRole('button', { name: /Belum Lunas/ }).click();
};
const billRow = (page, bill) => page.getByTestId('open-bills').getByRole('listitem').filter({ hasText: bill.invoice });
const notify = async (request, bill, orderId, status) =>
    (await request.post('/api/webhooks/midtrans', { data: midtransNotification(bill, orderId, status) })).status();

// REQ-005 J4: the guest and the cashier pay together. The first payment wins; a guest payment that comes second is marked
// "Bayar ganda — refund manual", written to the audit log and never booked, and the refund stays manual.
test.describe('80-dine-online-payment', () => {
    test.describe.configure({ timeout: 180_000 }); // several pages and a 10 s poll per step
    test.use({ actionTimeout: 30_000 });

    test('the cashier takes cash while the guest is paying online: the late online payment is marked and audited once, never booked', { tag: ['@REQ-005-AC-06'] }, async ({ arrange, page, request }) => {
        const bill = await guestStartsPaying(page, arrange, { staff: '1' });
        await signIn(page, bill.cashier_email);
        await openList(page);

        const row = billRow(page, bill);
        await expect(row).toContainText('Menunggu pembayaran online');
        await row.getByRole('button', { name: 'Bayar' }).click();
        const dialog = page.getByRole('dialog');
        // The notice comes before she submits: ask first, the guest's payment will not count, the refund is by hand.
        await expect(dialog.getByRole('status')).toContainText('Tanyakan dulu apakah tamu sudah membayar');
        await dialog.getByLabel('Uang diterima').fill(String(bill.grand_total));
        await dialog.getByRole('button', { name: 'Bayar', exact: true }).click();
        await page.waitForURL(new RegExp(`/transactions/${bill.invoice}/print`));

        // The cashier's payment moved the counter: the guest's charge (attempt 1) is superseded.
        expect(arrange('dine.order-state', { access_token: bill.access_token })).toMatchObject({
            payment_status: 'paid', payment_method: 'cash', payment_attempt: 2, late_superseded_payment_at: null,
        });

        // The gateway's settlement of the guest's charge arrives late: acknowledged, marked, audited, not booked.
        expect(await notify(request, bill, `${bill.invoice}-r1`, 'settlement')).toBe(200);
        const marked = arrange('dine.order-state', { access_token: bill.access_token });
        expect(marked).toMatchObject({ payment_status: 'paid', payment_method: 'cash', payment_attempt: 2 });
        expect(marked.late_superseded_payment_at).not.toBeNull();
        expect(marked.audit['payment.late_superseded']).toBe(1);
        expect(marked.audit['dine_order.online_payment_taken_over']).toBe(1);

        await page.goto(`/dashboard/transactions/history?invoice=${bill.invoice}`);
        await expect(page.getByRole('row').filter({ hasText: bill.invoice })).toContainText(LATE);

        // The gateway sends it again: nothing more is written.
        expect(await notify(request, bill, `${bill.invoice}-r1`, 'settlement')).toBe(200);
        expect(arrange('dine.order-state', { access_token: bill.access_token }).audit['payment.late_superseded']).toBe(1);
    });

    test('the gateway settles first: the cashier\'s stale Bayar is refused and nothing is paid twice', { tag: ['@REQ-005-AC-06'] }, async ({ arrange, page, request }) => {
        const bill = await guestStartsPaying(page, arrange, { staff: '1' });
        await signIn(page, bill.cashier_email);
        await openList(page);
        await billRow(page, bill).getByRole('button', { name: 'Bayar' }).click();
        const dialog = page.getByRole('dialog');
        await dialog.getByLabel('Uang diterima').fill(String(bill.grand_total));

        // Hold the list on its old state (no poll gets through), so the server's refusal is what this step proves
        // and a poll cannot land between the settlement and the click.
        const polls = '**/transactions/open-bills';
        await page.route(polls, (route) => route.abort());
        expect(await notify(request, bill, `${bill.invoice}-r1`, 'settlement')).toBe(200);
        await dialog.getByRole('button', { name: 'Bayar', exact: true }).click();

        await expect(page.getByText('Pesanan ini tidak memiliki tagihan yang belum lunas.').first()).toBeVisible();
        const state = arrange('dine.order-state', { access_token: bill.access_token });
        expect(state).toMatchObject({ payment_status: 'paid', payment_attempt: 1, late_superseded_payment_at: null });
        expect(state.payment_method).not.toBe('cash');

        // Once a poll gets through, the paid bill leaves the list but the open dialog stays: it says so and cannot submit.
        await page.unroute(polls);
        await expect(dialog.getByRole('alert')).toContainText('sudah tidak ada di daftar Belum Lunas', { timeout: 30_000 });
        await expect(dialog.getByRole('button', { name: 'Bayar', exact: true })).toBeDisabled();
        await expect(dialog.getByLabel('Uang diterima')).toHaveValue(String(bill.grand_total));
        await expect(billRow(page, bill)).toHaveCount(0);
    });

    test('a guest payment that arrives after its charge expired is booked while nobody else holds the bill, and audited once', { tag: ['@REQ-005-AC-06'] }, async ({ arrange, page, request }) => {
        const bill = await guestStartsPaying(page, arrange);

        expect(await notify(request, bill, `${bill.invoice}-r1`, 'expire')).toBe(200);
        expect(arrange('dine.order-state', { access_token: bill.access_token })).toMatchObject({ payment_status: 'failed', payment_failure_reason: 'expired' });

        // Money that did arrive is not lost: the bill is still unpaid and has no cashier tender, so the payment counts.
        expect(await notify(request, bill, `${bill.invoice}-r1`, 'settlement')).toBe(200);
        const booked = arrange('dine.order-state', { access_token: bill.access_token });
        expect(booked).toMatchObject({ payment_status: 'paid', payment_attempt: 1, late_superseded_payment_at: null });
        expect(booked.audit['payment.late_after_failed']).toBe(1);

        expect(await notify(request, bill, `${bill.invoice}-r1`, 'settlement')).toBe(200);
        const again = arrange('dine.order-state', { access_token: bill.access_token });
        expect(again).toMatchObject({ payment_status: 'paid', late_superseded_payment_at: null });
        expect(again.audit['payment.late_after_failed']).toBe(1);
    });

    test('a guest payment that arrives while the cashier collects her own QRIS is marked on her list and not booked', { tag: ['@REQ-005-AC-06'] }, async ({ arrange, page, request }) => {
        const bill = await guestStartsPaying(page, arrange, { staff: '1' });
        // The cashier takes over with her own QRIS: the bill is pending with a tender, so a late guest payment cannot be booked.
        arrange('dine.bill-act', { access_token: bill.access_token, action: 'qris-pending' });

        expect(await notify(request, bill, `${bill.invoice}-r1`, 'settlement')).toBe(200);
        const state = arrange('dine.order-state', { access_token: bill.access_token });
        expect(state).toMatchObject({ payment_status: 'pending', payment_attempt: 2 });
        expect(state.late_superseded_payment_at).not.toBeNull();
        expect(state.audit['payment.late_after_failed']).toBe(1);

        await signIn(page, bill.cashier_email);
        await openList(page);
        const row = billRow(page, bill);
        await expect(row).toContainText(LATE);
        await expect(row).not.toContainText('Menunggu pembayaran online');
        await expect(row.getByRole('link', { name: 'Lihat QR' })).toBeVisible();
    });
});
