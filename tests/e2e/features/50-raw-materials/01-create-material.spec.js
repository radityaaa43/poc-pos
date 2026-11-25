import { test, expect as baseExpect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';
import { warehouseCell } from '../../support/inventory.js';

const expect = baseExpect.configure({ timeout: 45_000 });

const manager = roles.find((role) => role.key === 'manager');
const cashier = roles.find((role) => role.key === 'cashier');

test.describe('50-raw-materials', () => {
    test.describe.configure({ timeout: 240_000 }); // a loaded runner needs more than the default 30 s
    test.use({ actionTimeout: 45_000 });

    test('create-material: a coffee bean bought in kg is saved in grams with an opening stock', {
        tag: ['@REQ-002-AC-02', '@REQ-002-AC-21'],
    }, async ({ asRole, arrange, uniq }) => {
        test.setTimeout(120_000);
        const title = uniq('Biji kopi');
        const page = await asRole(manager);

        await page.goto('/dashboard/raw-materials/create');
        // Barcode and SKU are not asked for.
        await expect(page.getByLabel(/barcode|sku/i)).toHaveCount(0);

        await page.getByLabel('Nama Bahan').fill(title);
        await page.getByLabel('Dibeli Oleh').selectOption({ label: 'Pusat (gudang pusat)' });
        await page.getByLabel('Satuan Stok').selectOption({ label: 'GRAM' });
        await page.getByLabel('Stok Minimum').fill('2500');

        // 1 kg = 1000 g at Rp 250.000, the default purchase unit.
        await page.getByRole('button', { name: 'Tambah Satuan Beli' }).click();
        await page.getByLabel('Satuan Beli', { exact: true }).selectOption({ label: 'KG' });
        await page.getByLabel('Isi per satuan beli').fill('1000');
        await page.getByLabel('Harga per satuan beli').fill('250000');
        await expect(page.getByText(/Harga acuan: Rp\s*250 per GRAM/)).toBeVisible();

        // Opening stock of 2 kg in the outlet warehouse.
        await page.getByLabel('Gudang', { exact: true }).selectOption({ label: 'WH-E2E - Gudang E2E' });
        await page.getByLabel('Jumlah stok awal').fill('2');
        await page.getByLabel('Satuan', { exact: true }).selectOption({ label: 'KG' });
        await page.getByRole('button', { name: 'Simpan' }).click();

        // AC-02: saved as a raw material with generated codes and a reference price of Rp 250 per gram.
        await expect(page).toHaveURL(/\/dashboard\/raw-materials$/);
        await page.goto(`/dashboard/raw-materials?search=${encodeURIComponent(title)}`);
        const row = page.getByRole('row', { name: new RegExp(title) });
        await expect(row).toContainText(/SKU: RM-[A-Z0-9]+/);
        await expect(row).toContainText('GRAM');
        await expect(row).toContainText('Pusat');
        await expect(row).toContainText(/KG \(isi 1\.000\)/);
        await expect(row).toContainText('2.500');

        // AC-21: 2 kg became 2.000 g in that warehouse, costed at the reference price.
        await expect(await warehouseCell(page, row, /WH-E2E/)).toContainText(/2\.000\s*·\s*Rp\s*250\b/);

        // The opening stock is on the stock history.
        await page.goto('/dashboard/stock-mutations');
        await page.locator('select').filter({ hasText: 'Semua Produk' }).selectOption({ label: title });
        await expect(page.getByRole('row', { name: new RegExp(`${title}.* in 2000 0 → 2000 Gudang E2E product_create`) })).toBeVisible();

        // AC-02: a raw material is not offered at the till.
        const { shift_id } = arrange('open-shift', { cash: 100000 });
        expect(shift_id).toBeGreaterThan(0);
        const till = await asRole(cashier);
        await till.goto('/dashboard/transactions');
        await till.getByPlaceholder(/cari/i).first().fill(title);
        await expect(till.getByText(title)).toHaveCount(0);
    });

    test('create-material: the form says what is missing', async ({ asRole }) => {
        const page = await asRole(manager);

        await page.goto('/dashboard/raw-materials/create');
        await page.getByRole('button', { name: 'Simpan' }).click();

        await expect(page.getByText('The title field is required.')).toBeVisible();
        await expect(page.getByText('The purchased by field is required.')).toBeVisible();
        await expect(page.getByText('The stock unit id field is required.')).toBeVisible();
        await expect(page).toHaveURL(/raw-materials\/create/);
    });
});
