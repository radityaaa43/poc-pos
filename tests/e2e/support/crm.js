import { expect } from '@playwright/test';

// Most dashboard forms put a <label> before its control without linking them (no htmlFor, no
// wrapping), so getByLabel finds nothing. The control is the label's next element sibling.
export const field = (page, label) => page
    .getByText(label, { exact: true })
    .locator('xpath=following-sibling::*[1]/descendant-or-self::*[self::input or self::select or self::textarea][1]');

// Rupiah as the UI prints it: "Rp 10.000" with a non-breaking space between.
export const rupiah = (amount) => new RegExp(`Rp\\s*${String(amount).replace(/\B(?=(\d{3})+(?!\d))/g, '\\.')}(?!\\d)`);

export const roleOf = (roles, key) => roles.find((role) => role.key === key);

// A table row (or any ancestor row) that holds the given text.
export const rowOf = (page, text) => page.getByRole('row').filter({ hasText: text });

export async function openPos(page) {
    await page.goto('/dashboard/transactions');
    await expect(page.getByPlaceholder(/Cari produk atau scan barcode/)).toBeVisible();
}

export async function pickCustomer(page, name) {
    await page.getByRole('button', { name: 'Pilih pelanggan...' }).click();
    await page.getByPlaceholder('Cari nama/telepon/nomor anggota...').fill(name);
    await page.getByRole('button', { name }).click();
}

export async function addProduct(page, title) {
    await page.getByPlaceholder(/Cari produk atau scan barcode/).fill(title);
    await page.getByRole('button', { name: title }).first().click();
    // The product tile plus its cart line; the cart is saved server side, so this can be slow on a busy machine.
    await expect(page.getByText(title, { exact: true })).toHaveCount(2, { timeout: 60_000 });
    await expect(page.getByText('Menghitung Promo...')).toHaveCount(0, { timeout: 60_000 });
}

// The grand total in the cart summary.
export const cartTotal = (page) => page.getByText('Total', { exact: true }).locator('xpath=following-sibling::span');

export async function payExactAndFinish(page) {
    await page.getByRole('button', { name: 'Uang pas' }).click();
    await page.getByRole('button', { name: 'Selesaikan Transaksi' }).click();
}

// A successful checkout shows the receipt (on the same URL) with a link back to the till.
export async function finishSale(page) {
    await payExactAndFinish(page);
    await expect(page.getByRole('link', { name: 'Kembali ke kasir' })).toBeVisible();
}

// A short unique code for things the UI upper-cases (vouchers).
export const code = (prefix) => `${prefix}${Math.random().toString(36).slice(2, 8).toUpperCase()}`;

// The shared <Search> box only searches on Enter and reloads the page through Inertia.
export async function searchCustomers(page, text) {
    await page.getByPlaceholder('Cari pelanggan...').fill(text);
    await page.getByPlaceholder('Cari pelanggan...').press('Enter');
    await expect(page).toHaveURL(/search=/);
    await expect(page.getByRole('heading', { name: text })).toBeVisible();
}
