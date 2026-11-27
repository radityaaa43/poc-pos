import { test, expect } from '../../support/fixtures.js';

const PAY_LATER_BUTTON = 'Pesan — Bayar Belakangan';
// The E2E outlet has a payment gateway, so its counter button reads Pesan (REQ-005); exact, so Bayar Belakangan never matches.
const PAY_AT_COUNTER_BUTTON = 'Pesan';
// The E2E outlet's helper also names paying online (REQ-005, #328).
const HELPER_TEXT = 'Pesanan langsung dibuat. Bayar di kasir atau online dari halaman pesanan sebelum Anda pergi.';
const REFUSAL = 'Bayar Belakangan tidak tersedia di outlet ini. Pilih Bayar di kasir.';

// Opens the table menu, puts one item in the cart and opens the cart dialog.
async function openCart(page, token, title) {
    await page.goto(`/dine/${token}`);
    const card = page.locator('div.rounded-xl', { has: page.getByRole('heading', { name: title, exact: true }) });
    await card.getByRole('button', { name: '+ Tambah' }).click();
    await page.getByRole('button', { name: /1 item/ }).click();

    return page.getByRole('dialog');
}

// Bayar Belakangan is the outlet's choice (REQ-003 AC-04): the menu offers it only while the setting is on, and the
// server refuses it otherwise. The setting is shared by the whole e2e database, so each test sets it and puts it back.
test.describe('37-dine-guest-menu', () => {
    test('with Bayar Belakangan on the cart offers both buttons and the helper text', { tag: ['@REQ-003-AC-04'] }, async ({ page, arrange, uniq }) => {
        const { token } = arrange('dine.table', { area: uniq('Area'), name: uniq('M') });
        const title = uniq('Kopi');
        arrange('product', { title, stock: 5 });

        arrange('paylater.setting', { enabled: 1 });
        try {
            const cart = await openCart(page, token, title);
            await expect(cart.getByRole('button', { name: PAY_AT_COUNTER_BUTTON, exact: true })).toBeVisible();
            await expect(cart.getByRole('button', { name: PAY_LATER_BUTTON })).toBeVisible();
            await expect(cart.getByText(HELPER_TEXT)).toBeVisible();
        } finally {
            arrange('paylater.setting', { enabled: 0 });
        }
    });

    test('with Bayar Belakangan off the cart offers only Bayar di Kasir', { tag: ['@REQ-003-AC-04'] }, async ({ page, arrange, uniq }) => {
        const { token } = arrange('dine.table', { area: uniq('Area'), name: uniq('M') });
        const title = uniq('Teh');
        arrange('product', { title, stock: 5 });

        arrange('paylater.setting', { enabled: 0 });
        const cart = await openCart(page, token, title);
        await expect(cart.getByRole('button', { name: PAY_AT_COUNTER_BUTTON, exact: true })).toBeVisible();
        await expect(cart.getByRole('button', { name: PAY_LATER_BUTTON })).toHaveCount(0);
        await expect(cart.getByText(HELPER_TEXT)).toHaveCount(0);
    });

    test('with Bayar Belakangan on, a guest who taps it sends a pay-later order that waits for staff', { tag: ['@REQ-003-AC-04'] }, async ({ page, arrange, uniq }) => {
        const { table_name, token } = arrange('dine.table', { area: uniq('Area'), name: uniq('M') });
        const title = uniq('Soto');
        const { product_id } = arrange('product', { title, stock: 5 });

        arrange('paylater.setting', { enabled: 1 });
        try {
            const cart = await openCart(page, token, title);
            await cart.getByRole('button', { name: PAY_LATER_BUTTON }).click();

            await expect(page).toHaveURL(/\/dine-order\/[0-9a-f-]{36}$/);
            await expect(page.getByText('Menunggu Konfirmasi', { exact: true })).toBeVisible();
            await expect(page.getByText(`Meja ${table_name}`)).toBeVisible();

            // Nothing is made or billed until staff accept: no transaction, no stock taken.
            const accessToken = new URL(page.url()).pathname.split('/').pop();
            expect(arrange('dine.order-state', { access_token: accessToken, product_id })).toMatchObject({
                status: 'submitted', payment_option: 'pay_later', invoice: null, kitchen_items: 0, product_stock: 5,
            });
        } finally {
            arrange('paylater.setting', { enabled: 0 });
        }
    });

    // The menu page only hides the button; a request sent straight to the endpoint must be refused too.
    test('with Bayar Belakangan off the order endpoint refuses a pay-later order and creates nothing', { tag: ['@REQ-003-AC-04'] }, async ({ page, arrange, uniq }) => {
        const { table_name, token } = arrange('dine.table', { area: uniq('Area'), name: uniq('M') });
        const { product_id } = arrange('product', { title: uniq('Pempek'), stock: 5 });

        arrange('paylater.setting', { enabled: 0 });
        await page.goto(`/dine/${token}`);
        const response = await page.evaluate(async ({ token, product_id }) => {
            const xsrf = decodeURIComponent(document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN=')).split('=')[1]);
            const res = await fetch(`/dine/${token}/order`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': xsrf },
                body: JSON.stringify({ items: [{ product_id, qty: 1 }], payment_option: 'pay_later' }),
            });

            return { status: res.status, body: await res.json() };
        }, { token, product_id });

        expect(response.status).toBe(422);
        expect(response.body.errors.order).toEqual([REFUSAL]);
        expect(arrange('dine.table-state', { name: table_name }).orders).toBe(0);
    });
});
