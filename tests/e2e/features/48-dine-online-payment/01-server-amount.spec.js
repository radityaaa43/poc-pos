import { test, expect } from '../../support/fixtures.js';
import { postPay } from '../../support/dine-bill.js';

test.describe('48-dine-online-payment', () => {
    // The amount is the bill's remaining total, worked out by the server: a number sent by the phone is never read.
    test('the charge is for the bill total and an amount sent by the phone is ignored', { tag: ['@REQ-005-AC-02'] }, async ({ arrange, page }) => {
        arrange('public.reset-throttle');
        const bill = arrange('dine.bill', { gateway: 'midtrans', needs_prep: '1' });
        await page.goto(`/dine-order/${bill.access_token}`);

        const response = await postPay(page, bill.access_token, { method: 'qris', amount: 1, grand_total: 1 });

        expect(response.status).toBe(200);
        expect(response.body.payment).toMatchObject({ state: 'pending', amount: bill.grand_total, attempt: 1 });
        expect(response.body.payment.qr).toMatch(/^data:image\/svg\+xml;base64,/);
        expect(response.body.payment.payment_url).toContain(`${bill.invoice}-r1`);
        // Nothing about the bill or the gateway beyond what the guest needs.
        expect(Object.keys(response.body.payment).sort()).toEqual(['amount', 'attempt', 'payment_url', 'qr', 'state']);

        const state = arrange('dine.order-state', { access_token: bill.access_token });
        expect(state).toMatchObject({ payment_status: 'pending', payment_attempt: 1, grand_total: bill.grand_total });
        expect(state.payment_reference).toBe(`${bill.invoice}-r1`);
    });

    test('the hosted payment page is charged for the same server total', { tag: ['@REQ-005-AC-02'] }, async ({ arrange, page }) => {
        arrange('public.reset-throttle');
        const bill = arrange('dine.bill', { gateway: 'xendit' });
        await page.goto(`/dine-order/${bill.access_token}`);

        const response = await postPay(page, bill.access_token, { method: 'gateway', amount: 1 });

        expect(response.status).toBe(200);
        expect(response.body.payment).toMatchObject({ state: 'pending', amount: bill.grand_total, attempt: 1 });
        expect(response.body.payment.payment_url).toContain(`${bill.invoice}-r1`);
        expect(arrange('dine.order-state', { access_token: bill.access_token })).toMatchObject({ payment_method: 'xendit', payment_attempt: 1 });
    });
});
