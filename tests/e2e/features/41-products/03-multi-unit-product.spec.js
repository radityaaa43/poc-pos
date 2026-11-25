import { test, expect } from '../../support/fixtures.js';
import { role, field, pick, card, openEdit, posSearch } from '../../support/inventory.js';

// The row of one unit in the product form's "Satuan" block.
const unitRow = (page, index) => page.locator(`#unit-${index}-unit_id`).locator('xpath=ancestor::div[contains(@class,"grid-cols-2")][1]');

test.describe('41-products', () => {
    test('a product with a base unit and a box of 12 keeps exactly one base unit', async ({ asRole, uniq }) => {
        const page = await asRole(role('super-admin'));
        const title = uniq('Susu');

        await page.goto('/dashboard/products/create');
        await pick(page, page.getByRole('button', { name: 'Pilih kategori' }), 'E2E Kategori');
        await page.getByPlaceholder('Masukkan kode produk').fill(uniq('BC'));
        await page.getByPlaceholder('Masukkan SKU unik').fill(uniq('SKU'));
        await page.getByPlaceholder('Masukkan nama produk').fill(title);
        await field(page, 'Harga Beli').first().fill('5000');
        await field(page, 'Harga Jual').first().fill('10000');
        await field(page, 'Stok').fill('24');
        await field(page, 'Persentase Pajak (%)').fill('0');

        // The first row is the base unit: content 1, field locked.
        await page.getByRole('button', { name: '+ Tambah Satuan' }).click();
        await page.locator('#unit-0-unit_id').selectOption({ label: 'PCS' });
        await expect(field(unitRow(page, 0), 'Konversi')).toBeDisabled();
        await expect(field(unitRow(page, 0), 'Konversi')).toHaveValue('1');

        await page.getByRole('button', { name: '+ Tambah Satuan' }).click();
        await page.locator('#unit-1-unit_id').selectOption({ label: 'BOX' });
        await field(unitRow(page, 1), 'Konversi').fill('12');
        await field(unitRow(page, 1), 'Harga Jual').fill('110000');

        // Making the box the base takes the flag off PCS and locks the box to content 1; and back.
        await unitRow(page, 1).getByLabel('Dasar').check();
        await expect(unitRow(page, 0).getByLabel('Dasar')).not.toBeChecked();
        await expect(field(unitRow(page, 1), 'Konversi')).toHaveValue('1');
        await unitRow(page, 0).getByLabel('Dasar').check();
        await expect(unitRow(page, 1).getByLabel('Dasar')).not.toBeChecked();
        await field(unitRow(page, 1), 'Konversi').fill('12');

        await page.getByRole('button', { name: 'Simpan Produk' }).click();
        await expect(page.getByText('Produk berhasil ditambahkan')).toBeVisible();

        await openEdit(page, title);
        await expect(page.locator('#unit-0-unit_id option:checked')).toHaveText('PCS');
        await expect(page.locator('#unit-1-unit_id option:checked')).toHaveText('BOX');
        await expect(unitRow(page, 0).getByLabel('Dasar')).toBeChecked();
        await expect(unitRow(page, 1).getByLabel('Dasar')).not.toBeChecked();
        await expect(field(unitRow(page, 1), 'Konversi')).toHaveValue(/^12(\.0+)?$/);
        await expect(field(unitRow(page, 1), 'Harga Jual')).toHaveValue('110000');
    });

    test('the POS sells the same product by piece or by box at each unit price', async ({ asRole, arrange, uniq }) => {
        const { product } = arrange('inventory.multi-unit', { prefix: uniq('MU') });
        arrange('open-shift', { cash: 100000 });
        const page = await asRole(role('cashier'));

        await page.goto('/dashboard/transactions');
        await posSearch(page, product);
        await expect(card(page, product)).toContainText('10.000');
        await card(page, product).getByRole('button', { name: 'BOX' }).click();
        await expect(card(page, product)).toContainText('110.000');
        await expect(card(page, product)).toContainText('/BOX');
        await card(page, product).getByRole('button', { name: 'Dasar' }).click();
        await expect(card(page, product)).not.toContainText('/BOX');
    });
});
