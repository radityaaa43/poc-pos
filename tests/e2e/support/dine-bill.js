import { createHash } from 'node:crypto';

// POST /dine-order/{token}/pay the way the guest page's axios sends it: JSON, with the XSRF token of the page's session.
// The page of any order must be open first (it sets the session and the XSRF cookie). Returns { status, body }.
export function postPay(page, token, body = {}) {
    return page.evaluate(async ({ token, body }) => {
        const xsrf = decodeURIComponent(document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN=')).split('=')[1]);
        const response = await fetch(`/dine-order/${token}/pay`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': xsrf },
            body: JSON.stringify(body),
        });

        return { status: response.status, body: await response.json().catch(() => null) };
    }, { token, body });
}

// A Midtrans notification the way the gateway posts it to /api/webhooks/midtrans, signed with the bill's outlet key
// (order_id + status_code + gross_amount + server key). `orderId` is what the gateway knows the charge by: `{invoice}-r{attempt}`.
// `status`: settlement | expire | deny. The reference (transaction_id) is the same for the same order id, so a repeat is a redelivery.
export function midtransNotification(bill, orderId, status, overrides = {}) {
    const body = { order_id: orderId, status_code: '200', gross_amount: `${bill.grand_total}.00`, transaction_status: status, transaction_id: `mid-${orderId}`, ...overrides };
    body.signature_key = createHash('sha512').update(body.order_id + body.status_code + body.gross_amount + bill.midtrans_server_key).digest('hex');

    return body;
}

// The guest of a `dine.bill` (see arrange options) presses Bayar sekarang with QRIS: attempt 1 is pending at the gateway.
export async function guestStartsPaying(page, arrange, options = {}) {
    arrange('public.reset-throttle');
    const bill = arrange('dine.bill', options);
    await page.goto(`/dine-order/${bill.access_token}`);
    const response = await postPay(page, bill.access_token, { method: 'qris' });
    if (response.status !== 200 || response.body.payment.attempt !== 1) {
        throw new Error(`the guest charge was not started: ${JSON.stringify(response)}`);
    }

    return bill;
}
