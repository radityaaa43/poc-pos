import { expect } from '@playwright/test';
import { roles } from './roles.js';

export const role = (key) => roles.find((r) => r.key === key);

// The dashboard forms render <label> without htmlFor, so a field is found through its label's sibling input.
// `scope` is a page or a locator (a unit row).
export const field = (scope, label) => scope.locator('label', { hasText: new RegExp(`^${label.replace(/[()%]/g, '\\$&')}$`) }).locator('xpath=following-sibling::div//input').first();

// Headless UI listbox (category picker, recipe component picker): open it, then pick the option by name.
export async function pick(page, button, name) {
    await button.click();
    await page.getByRole('option', { name }).click();
}

// Native confirm() dialogs (stock transfer actions) are accepted for the rest of the page's life.
export const acceptDialogs = (page) => page.on('dialog', (dialog) => dialog.accept());

// Confirms the SweetAlert delete dialog.
export const confirmDelete = (page) => page.getByRole('button', { name: 'Ya, Hapus!' }).click();

// A product card on a grid page (products list, POS): its heading sits inside a `group` div.
export const card = (page, title) => page.locator('div.group', { has: page.getByRole('heading', { name: title }) });

// Opens the edit page of the product the list is filtered to.
export async function openEdit(page, title) {
    await page.goto(`/dashboard/products?search=${title}`);
    await card(page, title).hover();
    await card(page, title).locator('a[href$="/edit"]').click();
}

export async function posSearch(page, text) {
    await page.getByPlaceholder(/^Cari produk atau scan barcode/).fill(text);
}

// Picks the named customer in the POS customer dropdown (a sale needs one).
export async function posCustomer(page, name) {
    await page.getByRole('button', { name: /Pilih pelanggan/ }).click();
    await page.getByPlaceholder('Cari nama/telepon/nomor anggota...').fill(name);
    await page.getByRole('button', { name: new RegExp(name) }).click();
}

// Exact cash, then complete the sale.
export async function posPayExact(page) {
    await page.getByRole('button', { name: 'Uang pas' }).click();
    await page.getByRole('button', { name: 'Selesaikan Transaksi' }).click();
}

// The first and second warehouse cell of a raw material row on the Raw Materials page
// (the manager's two warehouses, WH-E2E and WH-E2E-B).
export async function stockCells(page, title) {
    await page.goto(`/dashboard/raw-materials?search=${title}`);
    const cells = page.getByRole('row').filter({ hasText: title }).getByRole('cell');

    return [cells.nth(6), cells.nth(7)];
}

// Fills the transfer form WH-E2E to WH-E2E-B for one product and saves the draft; returns the transfer's document number.
export async function draftTransfer(page, product, qty, note) {
    await page.goto('/dashboard/stock-transfers/create');
    await page.locator('select').nth(0).selectOption({ label: 'WH-E2E — Gudang E2E' });
    await page.locator('select').nth(1).selectOption({ label: 'WH-E2E-B — Gudang E2E B' });
    await page.getByPlaceholder('Opsional').fill(note);
    await page.getByPlaceholder('Cari produk untuk ditambahkan...').fill(product);
    await page.getByRole('button', { name: new RegExp(product) }).click();
    await page.getByRole('spinbutton').fill(String(qty));
    await page.getByRole('button', { name: 'Simpan Draft' }).click();
    await expect(page.getByText('Draft', { exact: true })).toBeVisible();

    return (await page.getByRole('main').getByRole('heading', { level: 1 }).textContent()).trim();
}
