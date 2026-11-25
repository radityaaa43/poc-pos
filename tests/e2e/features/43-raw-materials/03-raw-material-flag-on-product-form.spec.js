import { test, expect } from '../../support/fixtures.js';
import { role, field, pick, card, openEdit } from '../../support/inventory.js';

test.describe('43-raw-materials', () => {
    test('ticking the raw material box on the product form saves a raw material', { tag: ['@REQ-001-AC-08'] }, async ({ asRole, uniq }) => {
        const page = await asRole(role('super-admin'));
        const title = uniq('Susu-ml');

        await page.goto('/dashboard/products/create');
        await pick(page, page.getByRole('button', { name: 'Pilih kategori' }), 'E2E Kategori');
        await page.getByPlaceholder('Masukkan kode produk').fill(uniq('RAW'));
        await page.getByPlaceholder('Masukkan SKU unik').fill(uniq('RAW'));
        await page.getByPlaceholder('Masukkan nama produk').fill(title);
        await field(page, 'Harga Beli').fill('20');
        await field(page, 'Harga Jual').fill('0');
        await field(page, 'Stok').fill('5000');
        await field(page, 'Persentase Pajak (%)').fill('0');
        await page.getByLabel('Bahan baku (tidak dijual di kasir)').check();
        await page.getByRole('button', { name: 'Simpan Produk' }).click();
        await expect(page.getByText('Produk berhasil ditambahkan')).toBeVisible();

        // The product list marks it, the raw material list holds it, and the edit form keeps the box ticked.
        await page.goto(`/dashboard/products?search=${title}`);
        await expect(card(page, title)).toContainText('Bahan Baku');
        await page.goto(`/dashboard/raw-materials?search=${title}`);
        await expect(page.getByRole('row').filter({ hasText: title })).toBeVisible();
        await openEdit(page, title);
        await expect(page.getByLabel('Bahan baku (tidak dijual di kasir)')).toBeChecked();
    });

    test('a product that is not flagged stays out of the raw material list', async ({ asRole, arrange, uniq }) => {
        const page = await asRole(role('super-admin'));
        const { title } = arrange('product', { title: uniq('Biasa'), stock: 3 });

        await page.goto(`/dashboard/raw-materials?search=${title}`);

        await expect(page.getByText('Belum Ada Bahan Baku')).toBeVisible();
    });
});
