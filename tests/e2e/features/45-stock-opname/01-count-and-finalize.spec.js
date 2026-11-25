import { test, expect } from '../../support/fixtures.js';
import { role, card } from '../../support/inventory.js';

// Starts a draft session for the first warehouse offered and adds `title` to it.
async function startSession(page, title, notes) {
    await page.goto('/dashboard/stock-opnames');
    await page.getByRole('link', { name: 'Buat Sesi Opname' }).first().click();
    await page.getByPlaceholder('Contoh: opname bulanan gudang depan').fill(notes);
    await page.getByRole('button', { name: 'Buat Sesi' }).click();
    await expect(page.getByText('Draft', { exact: true })).toBeVisible();

    await page.getByRole('button', { name: 'Tambah Produk' }).click();
    await page.getByPlaceholder('Cari nama produk, barcode, atau SKU...').fill(title);
    await page.getByRole('button', { name: new RegExp(title) }).click();
    await expect(page.getByText('Produk ditambahkan ke sesi')).toBeVisible();

    return page.getByRole('row').filter({ hasText: title });
}

test.describe('45-stock-opname', () => {
    test('a counter finds 2 missing units, explains it and finalizes the session', async ({ asRole, arrange, uniq }) => {
        const counter = await asRole(role('stock-opname'));
        const { title } = arrange('product', { title: uniq('Opname'), stock: 10 });

        const row = await startSession(counter, title, uniq('opname bulanan'));
        await expect(row).toContainText('10');
        await expect(row).toContainText('Belum dihitung');

        // Counted 8 against 10 in the system: a difference needs a reason before the session can close.
        await row.getByRole('spinbutton').fill('8');
        await expect(row).toContainText('-2');
        await expect(counter.getByRole('button', { name: 'Finalize Stock Opname' })).toBeDisabled();
        await row.getByRole('textbox').fill('Rusak di rak');
        await row.getByRole('button').click();
        await expect(counter.getByText('Item opname diperbarui')).toBeVisible();

        await expect(counter.getByRole('button', { name: 'Finalize Stock Opname' })).toBeEnabled();
        await counter.getByRole('button', { name: 'Finalize Stock Opname' }).click();
        await expect(counter.getByText('Stock opname difinalisasi')).toBeVisible();
        await expect(counter.getByText('Finalized', { exact: true })).toBeVisible();
        // A closed session is read-only.
        await expect(counter.getByRole('button', { name: 'Finalize Stock Opname' })).toHaveCount(0);
        await expect(row.getByRole('spinbutton')).toBeDisabled();

        // Side effects the manager can see: the stock moved and an adjustment line was written.
        const manager = await asRole(role('manager'));
        await manager.goto(`/dashboard/products?search=${title}`);
        await expect(card(manager, title).getByText('Stok: 8')).toBeVisible();
        await manager.goto('/dashboard/stock-mutations');
        await manager.locator('select').filter({ has: manager.locator('option', { hasText: 'Semua Produk' }) }).selectOption({ label: title });
        const mutation = manager.getByRole('row').filter({ hasText: title });
        await expect(mutation).toContainText('adjustment');
        await expect(mutation).toContainText('10 → 8');
        await expect(mutation).toContainText('stock_opname');
        await expect(mutation).toContainText('Rusak di rak');
    });

    test('a difference without a reason is refused and keeps the session open', async ({ asRole, arrange, uniq }) => {
        const counter = await asRole(role('stock-opname'));
        const { title } = arrange('product', { title: uniq('Selisih'), stock: 5 });

        const row = await startSession(counter, title, uniq('opname'));
        await row.getByRole('spinbutton').fill('7');
        await expect(row).toContainText('+2');
        await row.getByRole('button').click();

        await expect(counter.getByText('Gagal memperbarui item opname')).toBeVisible();
        await expect(counter.getByRole('button', { name: 'Finalize Stock Opname' })).toBeDisabled();
        await expect(counter.getByText('Draft', { exact: true })).toBeVisible();
    });

    test('an exact count needs no reason', async ({ asRole, arrange, uniq }) => {
        const counter = await asRole(role('stock-opname'));
        const { title } = arrange('product', { title: uniq('Pas'), stock: 6 });

        const row = await startSession(counter, title, uniq('opname'));
        await row.getByRole('spinbutton').fill('6');
        await row.getByRole('button').click();
        await expect(counter.getByText('Item opname diperbarui')).toBeVisible();
        await expect(counter.getByRole('button', { name: 'Finalize Stock Opname' })).toBeEnabled();
        await counter.getByRole('button', { name: 'Finalize Stock Opname' }).click();

        await expect(counter.getByText('Finalized', { exact: true })).toBeVisible();
    });
});
