import { test, expect } from '../../support/fixtures.js';

test.describe('37-dine-guest-menu', () => {
    // The menu page only blocks the button; a request sent straight to the endpoint must be refused too.
    test('with self-ordering switched off the order endpoint creates nothing', async ({ page, arrange, uniq }) => {
        const { table_name, token } = arrange('dine.table', { area: uniq('Area'), name: uniq('M') });
        const { product_id } = arrange('product', { title: uniq('Pempek'), stock: 5 });

        arrange('dine.self-order', { enabled: '0' });
        try {
            await page.goto(`/dine/${token}`);
            await page.evaluate(async ({ token, product_id }) => {
                const xsrf = decodeURIComponent(document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN=')).split('=')[1]);
                await fetch(`/dine/${token}/order`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': xsrf },
                    body: JSON.stringify({ items: [{ product_id, qty: 1 }], payment_option: 'pay_at_counter' }),
                });
            }, { token, product_id });

            expect(arrange('dine.table-state', { name: table_name }).orders).toBe(0);
        } finally {
            arrange('dine.self-order', { enabled: '1' });
        }
    });
});
