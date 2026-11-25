import { test, expect } from '../../support/fixtures.js';
import { role, warehouseCell } from '../../support/inventory.js';

test.describe('43-raw-materials', () => {
    test('a raw material is bought in kg and counted in pieces, with opening stock in one warehouse', async ({ asRole, uniq }) => {
        const page = await asRole(role('super-admin'));
        const title = uniq('Tepung');

        await page.goto('/dashboard/raw-materials');
        await page.getByRole('link', { name: 'Tambah Bahan Baku' }).first().click();
        await page.getByLabel('Nama Bahan').fill(title);
        await page.getByLabel('Dibeli Oleh').selectOption('central');
        await page.getByLabel('Satuan Stok').selectOption({ label: 'PCS' });
        await page.getByLabel('Stok Minimum').fill('500');

        // 1 KG holds 1.000 stock units and costs Rp 80.000, so the reference price is Rp 80 per unit.
        await page.getByRole('button', { name: 'Tambah Satuan Beli' }).click();
        await page.locator('#units-0-unit_id').selectOption({ label: 'KG' });
        await page.getByLabel('Isi per satuan beli').fill('1000');
        await page.getByLabel('Harga per satuan beli').fill('80000');
        await expect(page.getByText(/Harga acuan:\s*Rp\s*80 per PCS/)).toBeVisible();

        // Opening stock of 2 KG becomes 2.000 units in the chosen warehouse.
        await page.getByLabel('Gudang', { exact: true }).selectOption({ label: 'WH-E2E - Gudang E2E' });
        await page.getByLabel('Jumlah stok awal').fill('2');
        await page.locator('#opening_unit_id').selectOption({ label: 'KG' });
        await page.getByRole('button', { name: 'Simpan' }).click();

        await expect(page).toHaveURL(/\/dashboard\/raw-materials$/);
        await page.getByPlaceholder('Cari nama atau SKU...').fill(title);
        await page.getByPlaceholder('Cari nama atau SKU...').press('Enter');
        const row = page.getByRole('row').filter({ hasText: title });
        await expect(row).toContainText('PCS');
        await expect(row).toContainText('Pusat');
        await expect(row).toContainText('KG (isi 1.000)');
        const stock = await warehouseCell(page, row, /WH-E2E/);
        await expect(stock).toContainText('2.000');
        await expect(stock).toContainText('Rp 80');
    });

    test('the raw material form names what is missing', async ({ asRole }) => {
        const page = await asRole(role('manager'));

        await page.goto('/dashboard/raw-materials/create');
        await page.getByRole('button', { name: 'Simpan' }).click();

        await expect(page.getByText(/title field is required/i)).toBeVisible();
        await expect(page.getByText(/purchased by field is required/i)).toBeVisible();
        await expect(page.getByText(/stock unit id field is required/i)).toBeVisible();
        await expect(page).toHaveURL(/\/dashboard\/raw-materials\/create$/);
    });

    test('opening stock needs both a warehouse and an amount', async ({ asRole, uniq }) => {
        const page = await asRole(role('manager'));

        await page.goto('/dashboard/raw-materials/create');
        await page.getByLabel('Nama Bahan').fill(uniq('Gula'));
        await page.getByLabel('Dibeli Oleh').selectOption('outlet');
        await page.getByLabel('Satuan Stok').selectOption({ label: 'PCS' });
        await page.getByLabel('Jumlah stok awal').fill('5');
        await page.getByRole('button', { name: 'Simpan' }).click();

        await expect(page.getByText(/opening warehouse id field is required/i)).toBeVisible();
    });

    test('a cashier cannot open raw materials', async ({ asRole }) => {
        const page = await asRole(role('cashier'));

        const response = await page.goto('/dashboard/raw-materials');

        expect(response.status()).toBe(403);
    });
});
