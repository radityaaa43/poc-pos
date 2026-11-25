import { test, expect } from '../../support/fixtures.js';
import { role, field, openEdit } from '../../support/inventory.js';

test.describe('41-products', () => {
    // The release backfill (composite products start flagged) is covered by PHPUnit: the migration runs before the e2e app starts.
    test('@REQ-003-AC-01 a product can be flagged as needing preparation with a prep target', async ({ asRole, arrange, uniq }) => {
        const page = await asRole(role('super-admin'));
        const { title } = arrange('product', { title: uniq('Kopi'), stock: 5 });

        await openEdit(page, title);
        // The target is hidden until the product is flagged.
        await expect(field(page, 'Target waktu buat (menit)')).toHaveCount(0);

        await page.getByLabel('Perlu dibuat di dapur').check();
        await field(page, 'Target waktu buat (menit)').fill('7');
        // The arranged product has no SKU and the form requires one.
        await page.getByPlaceholder('SKU unik').fill(uniq('SKU'));
        await page.getByRole('button', { name: 'Simpan Perubahan' }).click();
        await expect(page.getByText('Produk berhasil diperbarui')).toBeVisible();

        await openEdit(page, title);
        await expect(page.getByLabel('Perlu dibuat di dapur')).toBeChecked();
        await expect(field(page, 'Target waktu buat (menit)')).toHaveValue('7');
    });
});
