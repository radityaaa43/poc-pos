import { test, expect } from '../../support/fixtures.js';
import { role, field, pick } from '../../support/inventory.js';

test.describe('41-products', () => {
    test('super-admin creates a simple product with opening stock', async ({ asRole, uniq }) => {
        const page = await asRole(role('super-admin'));
        const title = uniq('Roti');

        await page.goto('/dashboard/products');
        await page.getByRole('link', { name: 'Tambah Produk' }).first().click();
        await pick(page, page.getByRole('button', { name: 'Pilih kategori' }), 'E2E Kategori');
        await page.getByPlaceholder('Masukkan kode produk').fill(uniq('BC'));
        await page.getByPlaceholder('Masukkan SKU unik').fill(uniq('SKU'));
        await page.getByPlaceholder('Masukkan nama produk').fill(title);
        await field(page, 'Harga Beli').first().fill('6000');
        await field(page, 'Harga Jual').first().fill('10000');
        await field(page, 'Stok').fill('12');
        await field(page, 'Stok Minimum').fill('3');
        await field(page, 'Persentase Pajak (%)').fill('0');
        await page.getByRole('button', { name: 'Simpan Produk' }).click();

        await expect(page).toHaveURL(/\/dashboard\/products$/);
        await expect(page.getByText('Produk berhasil ditambahkan')).toBeVisible();
        await page.getByPlaceholder('Cari produk...').fill(title);
        await page.getByPlaceholder('Cari produk...').press('Enter');
        const card = page.locator('div.group', { has: page.getByRole('heading', { name: title }) });
        await expect(card.getByText('Stok: 12')).toBeVisible();
        await expect(card).toContainText('E2E Kategori');
        await expect(card).toContainText('10.000');
    });

    test('the product form names every missing field', async ({ asRole, uniq }) => {
        const page = await asRole(role('super-admin'));

        await page.goto('/dashboard/products/create');
        await page.getByRole('button', { name: 'Simpan Produk' }).click();

        await expect(page.getByText(/barcode field is required/i)).toBeVisible();
        await expect(page.getByText(/sku field is required/i)).toBeVisible();
        await expect(page.getByText(/title field is required/i)).toBeVisible();
        await expect(page.getByText(/category id field is required/i)).toBeVisible();
        await expect(page.getByText(/sell price field is required/i)).toBeVisible();
        await expect(page).toHaveURL(/\/dashboard\/products\/create$/);
    });

    test('a barcode that is already taken is refused', async ({ asRole, uniq }) => {
        const page = await asRole(role('super-admin'));

        await page.goto('/dashboard/products/create');
        await pick(page, page.getByRole('button', { name: 'Pilih kategori' }), 'E2E Kategori');
        await page.getByPlaceholder('Masukkan kode produk').fill('E2E-001'); // seeded by E2eSeeder
        await page.getByPlaceholder('Masukkan SKU unik').fill(uniq('SKU'));
        await page.getByPlaceholder('Masukkan nama produk').fill(uniq('Dobel'));
        await field(page, 'Harga Beli').first().fill('1000');
        await field(page, 'Harga Jual').first().fill('2000');
        await field(page, 'Stok').fill('1');
        await page.getByRole('button', { name: 'Simpan Produk' }).click();

        await expect(page.getByText(/barcode has already been taken/i)).toBeVisible();
    });

    test('a cashier cannot open the product list', async ({ asRole }) => {
        const page = await asRole(role('cashier'));

        const response = await page.goto('/dashboard/products');

        expect(response.status()).toBe(403);
    });
});
