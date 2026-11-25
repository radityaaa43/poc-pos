import { test, expect } from '../../support/fixtures.js';

// `request` is a fresh context with no cookies: a guest.
test('a guest opens the public invoice PDF with the right token', async ({ arrange, request }) => {
    arrange('public.reset-throttle');
    const sale = arrange('public.sale', { method: 'cash' });

    const response = await request.get(`/share/transactions/${sale.invoice}?token=${sale.token}`);

    expect(response.status()).toBe(200);
    expect(response.headers()['content-type']).toContain('application/pdf');
    expect((await response.body()).subarray(0, 4).toString()).toBe('%PDF');
});

// Bug #113: the controller asks for `no-store, max-age=0`, the response says `no-cache, private`.
test.fixme('the public invoice PDF is not stored by caches', async ({ arrange, request }) => {
    arrange('public.reset-throttle');
    const sale = arrange('public.sale', { method: 'cash' });

    const response = await request.get(`/share/transactions/${sale.invoice}?token=${sale.token}`);

    expect(response.headers()['cache-control']).toContain('no-store');
});
