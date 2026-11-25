import { test, expect } from '../../support/fixtures.js';
import { role, field, pick } from '../../support/inventory.js';

const filter = (page, firstOption) => page.locator('select').filter({ has: page.locator('option', { hasText: firstOption }) });

test.describe('44-stock-mutations', () => {
    test('a new product leaves an initial stock line in the mutation history', async ({ asRole, uniq }) => {
        const admin = await asRole(role('super-admin'));
        const title = uniq('Mutasi');

        await admin.goto('/dashboard/products/create');
        await pick(admin, admin.getByRole('button', { name: 'Pilih kategori' }), 'E2E Kategori');
        await admin.getByPlaceholder('Masukkan kode produk').fill(uniq('BC'));
        await admin.getByPlaceholder('Masukkan SKU unik').fill(uniq('SKU'));
        await admin.getByPlaceholder('Masukkan nama produk').fill(title);
        await field(admin, 'Harga Beli').fill('3000');
        await field(admin, 'Harga Jual').fill('5000');
        await field(admin, 'Stok').fill('12');
        await field(admin, 'Persentase Pajak (%)').fill('0');
        await admin.getByRole('button', { name: 'Simpan Produk' }).click();
        await expect(admin.getByText('Produk berhasil ditambahkan')).toBeVisible();

        await admin.goto('/dashboard/stock-mutations');
        await filter(admin, 'Semua Produk').selectOption({ label: title });
        await expect(admin).toHaveURL(/product_id=/);
        const row = admin.getByRole('row').filter({ hasText: title });
        await expect(row).toHaveCount(1);
        await expect(row).toContainText('in');
        await expect(row).toContainText('0 → 12');
        await expect(row).toContainText('product_create');
        await expect(row).toContainText('Initial stock saat produk dibuat.');
        await expect(row).toContainText('E2E Super Admin');

        // The type filter narrows the same product to nothing: it only ever had an "in" line.
        await filter(admin, 'Semua Tipe').selectOption('out');
        await expect(admin).toHaveURL(/mutation_type=out/);
        await expect(admin.getByText('Belum ada mutasi stok.')).toBeVisible();
        await filter(admin, 'Semua Tipe').selectOption('in');
        await expect(admin).toHaveURL(/mutation_type=in/);
        await expect(admin.getByRole('row').filter({ hasText: title })).toHaveCount(1);
    });

    test('only roles with stock mutation access can read the history', async ({ asRole }) => {
        const manager = await asRole(role('manager'));
        await manager.goto('/dashboard/stock-mutations');
        await expect(manager.getByRole('main').getByRole('heading', { name: 'Mutasi Stok' })).toBeVisible();

        for (const key of ['cashier', 'stock-opname']) {
            const page = await asRole(role(key));
            const response = await page.goto('/dashboard/stock-mutations');
            expect(response.status(), key).toBe(403);
        }
    });
});
