import { test, expect } from '../../support/fixtures.js';
import { role, field, card, openEdit, confirmDelete } from '../../support/inventory.js';

test.describe('41-products', () => {
    test('editing a product changes its details but not its stock', async ({ asRole, arrange, uniq }) => {
        const page = await asRole(role('super-admin'));
        const { title } = arrange('product', { title: uniq('Kue'), stock: 8 });
        const renamed = `${title}-v2`;

        await page.goto(`/dashboard/products?search=${title}`);
        await expect(card(page, title).getByText('Stok: 8')).toBeVisible();
        await openEdit(page, title);

        // Stock is shown but cannot be typed: it moves through sales, receiving, transfers and opname.
        await expect(page.getByText('Stok Saat Ini')).toBeVisible();
        await expect(page.getByText('Perubahan stok dilakukan melalui transaksi atau stock opname.')).toBeVisible();
        await expect(field(page, 'Stok')).toHaveCount(0);

        await page.getByPlaceholder('Nama produk').fill(renamed);
        // The arranged product has no SKU and the form requires one.
        await page.getByPlaceholder('SKU unik').fill(uniq('SKU'));
        await field(page, 'Harga Jual').fill('12500');
        await page.getByRole('button', { name: 'Simpan Perubahan' }).click();

        await expect(page.getByText('Produk berhasil diperbarui')).toBeVisible();
        await page.goto(`/dashboard/products?search=${renamed}`);
        await expect(card(page, renamed)).toContainText('12.500');
        await expect(card(page, renamed).getByText('Stok: 8')).toBeVisible();
    });

    test('deleting a product removes it from the list', async ({ asRole, arrange, uniq }) => {
        const page = await asRole(role('super-admin'));
        const { title } = arrange('product', { title: uniq('Hapus'), stock: 1 });

        await page.goto(`/dashboard/products?search=${title}`);
        await card(page, title).hover();
        await card(page, title).locator('button:has(svg.tabler-icon-trash)').click();
        await confirmDelete(page);

        await expect(page.getByRole('heading', { name: title })).toHaveCount(0);
    });
});
