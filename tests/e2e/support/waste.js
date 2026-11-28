import { pick } from './inventory.js';

// Opens the record form for the E2E warehouse and fills one item; the caller saves.
export async function fillWaste(page, { item, qty, note }) {
    await page.goto('/dashboard/stock-waste/create');
    await page.getByLabel('Gudang', { exact: true }).selectOption({ label: 'WH-E2E — Gudang E2E' });
    await pick(page, page.getByRole('button', { name: 'Pilih bahan baku atau menu' }), item);
    await page.getByRole('spinbutton').fill(String(qty));
    await page.getByLabel('Alasan', { exact: true }).selectOption({ label: 'Rusak' });
    await page.getByLabel('Catatan (opsional)').fill(note);
}

// The list row of the record carrying that note.
export const wasteRow = (page, note) => page.getByRole('listitem').filter({ hasText: note });
