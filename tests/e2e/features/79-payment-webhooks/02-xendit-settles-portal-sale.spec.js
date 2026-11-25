import { test, expect } from '../../support/fixtures.js';

const body = (sale, status) => ({ external_id: sale.invoice, id: `xnd-${sale.invoice}`, status });
const portal = (sale) => `/portal/transactions/${sale.invoice}?token=${sale.token}`;

test('a PAID callback with the right token marks the sale paid on the portal', async ({ arrange, page, request }) => {
    arrange('public.reset-throttle');
    const sale = arrange('public.sale', { method: 'pay_later', gateway: 'xendit' });

    const response = await request.post('/api/webhooks/xendit', {
        data: body(sale, 'PAID'),
        headers: { 'X-CALLBACK-TOKEN': sale.xendit_callback_token },
    });
    expect(response.status()).toBe(200);

    await page.goto(portal(sale));
    await expect(page.getByText('Lunas', { exact: true })).toBeVisible();
});

test('a callback with a wrong token is refused and the sale stays unpaid', async ({ arrange, page, request }) => {
    arrange('public.reset-throttle');
    const sale = arrange('public.sale', { method: 'pay_later', gateway: 'xendit' });

    const response = await request.post('/api/webhooks/xendit', { data: body(sale, 'PAID'), headers: { 'X-CALLBACK-TOKEN': 'wrong' } });
    expect(response.status()).toBe(403);

    await page.goto(portal(sale));
    await expect(page.getByText('Belum Lunas', { exact: true })).toBeVisible();
});
