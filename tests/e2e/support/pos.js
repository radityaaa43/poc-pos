import { expect } from '@playwright/test';

// Helpers for the POS checkout group (features 20-24). The POS is /dashboard/transactions; the
// cashier needs an open shift (`arrange('pos.open-shift')`).

// 12000 -> 'Rp 12.000' (the UI prints Intl currency; Playwright text matching ignores the NBSP).
export const rupiah = (amount) => `Rp ${amount.toLocaleString('id-ID')}`;

export async function openPos(page) {
    await page.goto('/dashboard/transactions');
    // First load of the Inertia bundle can be slow on a busy machine.
    await expect(page.getByPlaceholder(/Cari produk atau scan barcode/)).toBeVisible({ timeout: 45_000 });
    // A press right after first paint is answered but its error toast is lost, so let the page settle.
    await page.waitForLoadState('networkidle');
}

// Clicks a product card in the grid and waits for the server to take it. (The "ditambahkan" toast lasts about
// two seconds, which a busy machine can make a polling assertion miss; a click that lands while the page is
// still swapping in the previous response is dropped, so press again only when nothing was sent.)
export async function addToCart(page, title, { unit } = {}) {
    if (unit) {
        await productCard(page, title).getByRole('button', { name: unit, exact: true }).click();
    }
    await expect(async () => {
        // The card is disabled while the previous add is still being applied.
        await expect(cardButton(page, title)).toBeEnabled({ timeout: 15_000 });
        const sent = page.waitForResponse((response) => response.url().endsWith('/addToCart'), { timeout: 10_000 });
        await cardButton(page, title).click();
        await sent;
    }).toPass({ timeout: 45_000 });
}

// The image button that adds a product; its name is "<title> [Sisa n] + Tambah" (the badge shows at 5 or less).
export const cardButton = (page, title) => page.getByRole('button', { name: new RegExp(`^${title}( Sisa \\d+)? \\+ Tambah`) });

// The grid card of a product (image button + info block); scoped for the unit buttons under the price.
export const productCard = (page, title) =>
    page.getByRole('heading', { name: title, level: 3 }).locator('xpath=ancestor::div[contains(@class,"rounded-2xl")][1]');

// "Total" row of the payment summary.
export const total = (page) => page.getByText('Total', { exact: true }).locator('xpath=following-sibling::span');

// "Jumlah Bayar" input and the "Kembalian" amount of the cash panel.
export const cashInput = (page) => page.getByText('Jumlah Bayar', { exact: true }).locator('xpath=following-sibling::div//input');
export const change = (page) => page.getByText('Kembalian', { exact: true }).first().locator('xpath=following-sibling::span');

export async function chooseCustomer(page, name) {
    await page.getByRole('button', { name: 'Pilih pelanggan...' }).click();
    await page.getByPlaceholder('Cari nama/telepon/nomor anggota...').fill(name);
    await page.getByRole('button', { name: new RegExp(name) }).click();
}

// Submits the checkout and waits for the receipt page; returns the invoice number.
export async function finish(page) {
    await page.getByRole('button', { name: 'Selesaikan Transaksi' }).click();
    await page.waitForURL(/\/dashboard\/transactions\/TRX-[A-Z0-9]+\/print$/);

    return page.url().match(/(TRX-[A-Z0-9]+)\/print/)[1];
}

// A barcode scanner is a keyboard that types the code and Enter faster than a person (useBarcodeScanner
// ignores keys while an input has focus, so blur the search box first). The keys are dispatched in
// one tick so a slow machine cannot stretch the gap past the hook's 50 ms limit.
export async function scan(page, code) {
    await page.getByRole('heading', { name: 'Keranjang', level: 3 }).click();
    await page.evaluate((value) => {
        for (const key of [...value, 'Enter']) window.dispatchEvent(new KeyboardEvent('keydown', { key }));
    }, code);
}

// Presses "+" on the cart row and waits for the server to answer. Inertia drops a click that lands while the
// page is still swapping in the cart the previous request returned, so press again only when nothing was sent.
export async function increment(page) {
    await expect(async () => {
        const sent = page.waitForResponse((response) => response.url().includes('/updateCart') && response.request().method() === 'PATCH', { timeout: 5_000 });
        await page.getByRole('button', { name: '+', exact: true }).click();
        await sent;
    }).toPass({ timeout: 30_000 });
}
