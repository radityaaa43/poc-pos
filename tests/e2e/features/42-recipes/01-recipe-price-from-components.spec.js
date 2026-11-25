import { test, expect } from '../../support/fixtures.js';
import { role, field, pick, card, posSearch } from '../../support/inventory.js';

const fillBasics = async (page, uniq, title) => {
    await pick(page, page.getByRole('button', { name: 'Pilih kategori' }), 'E2E Kategori');
    await page.getByPlaceholder('Masukkan kode produk').fill(uniq('BC'));
    await page.getByPlaceholder('Masukkan SKU unik').fill(uniq('SKU'));
    await page.getByPlaceholder('Masukkan nama produk').fill(title);
    await field(page, 'Harga Beli').fill('1000');
    await field(page, 'Persentase Pajak (%)').fill('0');
};

test.describe('42-recipes', () => {
    test('a recipe without its own price sells at the total of its components', { tag: ['@REQ-001-AC-02'] }, async ({ asRole, arrange, uniq }) => {
        const admin = await asRole(role('super-admin'));
        const title = uniq('Paket');

        await admin.goto('/dashboard/products/create');
        await fillBasics(admin, uniq, title);
        await admin.getByLabel('Produk Komposit (resep / paket)').check();
        // Composite: stock is derived from the components, the price field says what an empty price means.
        await expect(admin.getByPlaceholder('Dari stok komponen')).toBeDisabled();
        await expect(admin.getByPlaceholder('Kosong = total harga komponen')).toBeEmpty();

        await admin.getByRole('button', { name: '+ Tambah Komponen' }).click();
        await pick(admin, admin.getByRole('button', { name: 'Pilih produk komponen' }).last(), 'Produk E2E 1');
        await admin.getByPlaceholder('Qty').last().fill('2');
        await admin.getByRole('button', { name: '+ Tambah Komponen' }).click();
        await pick(admin, admin.getByRole('button', { name: 'Pilih produk komponen' }).last(), 'Produk E2E 2');
        // 2 x Rp 10.000 + 1 x Rp 15.000
        await expect(admin.getByText(/Total harga jual komponen:\s*Rp\s*35\.000/)).toBeVisible();

        await admin.getByRole('button', { name: 'Simpan Produk' }).click();
        await expect(admin.getByText('Produk berhasil ditambahkan')).toBeVisible();

        arrange('open-shift', { cash: 100000 });
        const cashier = await asRole(role('cashier'));
        await cashier.goto('/dashboard/transactions');
        await posSearch(cashier, title);
        await expect(card(cashier, title)).toContainText('35.000');
    });

    test('a recipe needs at least one component', async ({ asRole, uniq }) => {
        const page = await asRole(role('super-admin'));

        await page.goto('/dashboard/products/create');
        await fillBasics(page, uniq, uniq('Kosong'));
        await page.getByLabel('Produk Komposit (resep / paket)').check();
        await page.getByRole('button', { name: 'Simpan Produk' }).click();

        await expect(page.getByText(/components field is required/i)).toBeVisible();
        await expect(page).toHaveURL(/\/dashboard\/products\/create$/);
    });
});
