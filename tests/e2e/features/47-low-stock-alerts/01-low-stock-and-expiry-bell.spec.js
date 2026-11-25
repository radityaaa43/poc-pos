import { test, expect } from '../../support/fixtures.js';
import { role } from '../../support/inventory.js';

const bell = (page) => page.locator('button:has(svg.tabler-icon-bell)').first();

test.describe('47-low-stock-alerts', () => {
    test('the bell warns about a product at its minimum and a batch about to expire, and read alerts stay read', async ({ asRole, arrange, uniq }) => {
        const page = await asRole(role('manager'));
        // 2 in stock against a minimum of 5, with a batch of those 2 that expires in 10 days.
        const { product, batch } = arrange('inventory.low-stock', { prefix: uniq('Alert'), stock: 2, min: 5 });

        await page.goto('/dashboard/transactions');
        await bell(page).click();
        const lowStock = page.getByText(`Stok habis: ${product}`);
        await expect(lowStock).toBeVisible();
        await expect(page.getByText(`Stok habis: ${product}`).locator('xpath=following-sibling::div')).toContainText('Stok: 2');
        await expect(page.getByText(`Batch kedaluwarsa: ${product}`)).toBeVisible();
        await expect(page.getByText(`Batch kedaluwarsa: ${product}`).locator('xpath=following-sibling::div')).toContainText(`${batch} • Stok: 2`);

        // Reading the stock alert dismisses it for this user; the expiry alert has no read button.
        const alert = lowStock.locator('xpath=ancestor::div[contains(@class,"justify-between")][1]');
        const saved = page.waitForResponse((r) => r.url().includes('/notifications/low-stock/read') && r.request().method() === 'POST');
        await alert.getByRole('button', { name: 'Dibaca' }).click();
        await saved;
        await expect(lowStock).toHaveCount(0);
        await expect(page.getByText(`Batch kedaluwarsa: ${product}`).locator('xpath=ancestor::div[contains(@class,"justify-between")][1]').getByRole('button', { name: 'Dibaca' })).toBeDisabled();

        await page.reload();
        await bell(page).click();
        await expect(page.getByText(`Stok habis: ${product}`)).toHaveCount(0);
        await expect(page.getByText(`Batch kedaluwarsa: ${product}`)).toBeVisible();
    });

    test('restocking above the minimum clears the alert', async ({ asRole, arrange, uniq }) => {
        const page = await asRole(role('manager'));
        const { product } = arrange('inventory.low-stock', { prefix: uniq('Isi'), stock: 2, min: 5 });

        await page.goto('/dashboard/transactions');
        await bell(page).click();
        await expect(page.getByText(`Stok habis: ${product}`)).toBeVisible();

        // Product stock is raised through an opname, the only way to change it by hand.
        const counter = await asRole(role('stock-opname'));
        await counter.goto('/dashboard/stock-opnames/create');
        await counter.getByRole('button', { name: 'Buat Sesi' }).click();
        await counter.getByRole('button', { name: 'Tambah Produk' }).click();
        await counter.getByPlaceholder('Cari nama produk, barcode, atau SKU...').fill(product);
        await counter.getByRole('button', { name: new RegExp(product) }).click();
        const row = counter.getByRole('row').filter({ hasText: product });
        await row.getByRole('spinbutton').fill('30');
        await row.getByRole('textbox').fill('Barang datang');
        await row.getByRole('button').click();
        await expect(counter.getByText('Item opname diperbarui')).toBeVisible();
        await counter.getByRole('button', { name: 'Finalize Stock Opname' }).click();
        await expect(counter.getByText('Finalized', { exact: true })).toBeVisible();

        await page.reload();
        await bell(page).click();
        await expect(page.getByText(`Stok habis: ${product}`)).toHaveCount(0);
    });
});
