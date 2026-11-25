import { createHash } from 'node:crypto';
import { test, expect } from '../../support/fixtures.js';

// What Midtrans signs: order_id + status_code + gross_amount + server key (PaymentWebhookController).
const notification = (sale, status, overrides = {}, tamper = {}) => {
    const body = { order_id: sale.invoice, status_code: '200', gross_amount: `${sale.grand_total}.00`, transaction_status: status, transaction_id: `mid-${sale.invoice}`, ...overrides };
    body.signature_key = createHash('sha512').update(body.order_id + body.status_code + body.gross_amount + sale.midtrans_server_key).digest('hex');

    return { ...body, ...tamper };
};
const portal = (sale) => `/portal/transactions/${sale.invoice}?token=${sale.token}`;

test('a settlement notification marks the sale paid on the customer portal', async ({ arrange, page, request }) => {
    arrange('public.reset-throttle');
    const sale = arrange('public.sale', { method: 'pay_later', gateway: 'midtrans' });
    await page.goto(portal(sale));
    await expect(page.getByText('Belum Lunas', { exact: true })).toBeVisible();

    const response = await request.post('/api/webhooks/midtrans', { data: notification(sale, 'settlement') });
    expect(response.status()).toBe(200);
    expect(await response.json()).toEqual({ status: 'success' });

    await page.goto(portal(sale));
    await expect(page.getByText('Lunas', { exact: true })).toBeVisible();
    await expect(page.getByText('Belum Lunas', { exact: true })).toHaveCount(0);
});

test('a paid sale no longer asks the customer for the balance', async ({ arrange, page, request }) => {
    arrange('public.reset-throttle');
    const sale = arrange('public.sale', { method: 'pay_later', gateway: 'midtrans' });
    await request.post('/api/webhooks/midtrans', { data: notification(sale, 'settlement') });

    await page.goto(portal(sale));

    await expect(page.getByText('Lunas', { exact: true })).toBeVisible();
    await expect(page.getByText('Sisa Pembayaran')).toHaveCount(0);
    await expect(page.getByRole('link', { name: 'Bayar Sekarang' })).toHaveCount(0);
});

test('an expired payment shows as not paid and a later settlement is ignored', async ({ arrange, page, request }) => {
    arrange('public.reset-throttle');
    const sale = arrange('public.sale', { method: 'pay_later', gateway: 'midtrans' });

    expect((await request.post('/api/webhooks/midtrans', { data: notification(sale, 'expire') })).status()).toBe(200);
    await page.goto(portal(sale));
    // A failed payment is labelled "Gagal" (#124).
    await expect(page.getByText('Gagal', { exact: true })).toBeVisible();

    // A failed sale is terminal: a late settlement must not flip it (idempotency guard).
    expect((await request.post('/api/webhooks/midtrans', { data: notification(sale, 'settlement') })).status()).toBe(200);
    await page.goto(portal(sale));
    await expect(page.getByText('Gagal', { exact: true })).toBeVisible();
    await expect(page.getByText('Lunas', { exact: true })).toHaveCount(0);
});

test('a notification with a bad signature or an unknown invoice is refused', async ({ arrange, request }) => {
    arrange('public.reset-throttle');
    const sale = arrange('public.sale', { method: 'pay_later', gateway: 'midtrans' });

    const forged = await request.post('/api/webhooks/midtrans', { data: notification(sale, 'settlement', {}, { signature_key: 'forged' }) });
    expect(forged.status()).toBe(403);

    // Unknown invoice: no outlet, so the shared settings are used and the server key does not match.
    const unknown = await request.post('/api/webhooks/midtrans', { data: notification(sale, 'settlement', { order_id: 'INV-NOBODY' }) });
    expect([403, 404]).toContain(unknown.status());
});
