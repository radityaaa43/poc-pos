import { test, expect } from '../../support/fixtures.js';
import { postPay } from '../../support/dine-bill.js';

const refused = (response, status, code) => {
    expect(response.status).toBe(status);
    expect(response.body.errors.payment).toEqual([code]);
};

// Every refusal below must also leave the bill untouched: no payment attempt, no charge.
test.describe('48-dine-online-payment', () => {
    test('a request without a valid order token is refused', { tag: ['@REQ-005-AC-09'] }, async ({ arrange, page }) => {
        arrange('public.reset-throttle');
        const bill = arrange('dine.bill');
        await page.goto(`/dine-order/${bill.access_token}`);

        const response = await postPay(page, '00000000-0000-4000-8000-000000000000', { method: 'qris' });

        expect(response.status).toBe(404);
        expect(arrange('dine.order-state', { access_token: bill.access_token })).toMatchObject({ payment_status: 'unpaid', payment_attempt: 0 });
    });

    test('before staff accept the order it cannot be paid', { tag: ['@REQ-005-AC-09'] }, async ({ arrange, page }) => {
        arrange('public.reset-throttle');
        const bill = arrange('dine.bill', { accepted: '0' });
        await page.goto(`/dine-order/${bill.access_token}`);

        refused(await postPay(page, bill.access_token, { method: 'qris' }), 422, 'not_payable');

        expect(arrange('dine.order-state', { access_token: bill.access_token })).toMatchObject({ status: 'submitted', payment_status: null });
    });

    test('a rejected order cannot be paid', { tag: ['@REQ-005-AC-09'] }, async ({ arrange, page }) => {
        arrange('public.reset-throttle');
        const bill = arrange('dine.bill', { accepted: '0' });
        arrange('dine.bill-act', { access_token: bill.access_token, action: 'reject' });
        await page.goto(`/dine-order/${bill.access_token}`);

        refused(await postPay(page, bill.access_token, { method: 'qris' }), 422, 'not_payable');

        expect(arrange('dine.order-state', { access_token: bill.access_token })).toMatchObject({ status: 'rejected', payment_status: null });
    });

    test('a paid bill is refused as already paid and no charge is made', { tag: ['@REQ-005-AC-09'] }, async ({ arrange, page }) => {
        arrange('public.reset-throttle');
        const bill = arrange('dine.bill');
        arrange('dine.bill-act', { access_token: bill.access_token, action: 'cash-paid' });
        await page.goto(`/dine-order/${bill.access_token}`);

        refused(await postPay(page, bill.access_token, { method: 'qris' }), 422, 'already_paid');

        expect(arrange('dine.order-state', { access_token: bill.access_token })).toMatchObject({ payment_status: 'paid', payment_attempt: 0 });
    });

    test('the sixth request in a minute is refused, per order token', { tag: ['@REQ-005-AC-09'] }, async ({ arrange, page }) => {
        arrange('public.reset-throttle');
        const first = arrange('dine.bill', { accepted: '0' });
        const other = arrange('dine.bill', { accepted: '0' });
        await page.goto(`/dine-order/${first.access_token}`);

        for (let i = 0; i < 5; i++) {
            refused(await postPay(page, first.access_token, { method: 'qris' }), 422, 'not_payable');
        }
        expect((await postPay(page, first.access_token, { method: 'qris' })).status).toBe(429);

        // Another order, from the same phone and IP, has its own allowance.
        refused(await postPay(page, other.access_token, { method: 'qris' }), 422, 'not_payable');
    });
});
