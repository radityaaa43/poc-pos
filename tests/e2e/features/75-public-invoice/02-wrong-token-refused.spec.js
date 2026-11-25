import { test, expect } from '../../support/fixtures.js';

test('a wrong, missing or foreign token does not open the invoice', async ({ arrange, request }) => {
    arrange('public.reset-throttle');
    const sale = arrange('public.sale', { method: 'cash' });
    const other = arrange('public.sale', { method: 'cash' });
    const open = (invoice, token) => request.get(`/share/transactions/${invoice}`, { params: token ? { token } : {} });

    expect((await open(sale.invoice, 'not-the-token')).status()).toBe(404);
    expect((await open(sale.invoice)).status()).toBe(404);
    // A token only opens its own invoice.
    expect((await open(sale.invoice, other.token)).status()).toBe(404);
    expect((await open('INV-DOES-NOT-EXIST', sale.token)).status()).toBe(404);
});
