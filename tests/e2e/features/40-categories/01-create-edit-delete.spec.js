import { test, expect } from '../../support/fixtures.js';
import { role, confirmDelete } from '../../support/inventory.js';

test.describe('40-categories', () => {
    test('a manager adds, renames and deletes a category', async ({ asRole, uniq }) => {
        const page = await asRole(role('manager'));
        const name = uniq('Kategori');
        const renamed = `${name}-baru`;

        await page.goto('/dashboard/categories');
        await page.getByRole('link', { name: 'Tambah Kategori' }).first().click();
        await page.getByPlaceholder('Masukkan nama').fill(name);
        await page.getByPlaceholder('Deskripsi kategori').fill('Dibuat oleh e2e');
        await page.getByRole('button', { name: 'Simpan' }).click();

        await expect(page).toHaveURL(/\/dashboard\/categories$/);
        await expect(page.getByText('Kategori berhasil ditambahkan')).toBeVisible();
        await page.getByPlaceholder('Cari kategori...').fill(name);
        await page.getByPlaceholder('Cari kategori...').press('Enter');
        await expect(page.getByRole('heading', { name })).toBeVisible();

        // Grid cards show their actions on hover; the edit link is an icon-only link.
        await page.getByRole('heading', { name }).hover();
        await page.locator('a[href*="/dashboard/categories/"][href$="/edit"]').first().click();
        await page.getByPlaceholder('Masukkan nama').fill(renamed);
        await page.getByRole('button', { name: 'Simpan Perubahan' }).click();
        await expect(page.getByText('Kategori berhasil diperbarui')).toBeVisible();
        await page.getByPlaceholder('Cari kategori...').fill(renamed);
        await page.getByPlaceholder('Cari kategori...').press('Enter');
        await expect(page.getByRole('heading', { name: renamed })).toBeVisible();

        await page.getByRole('heading', { name: renamed }).hover();
        await page.locator('button:has(svg.tabler-icon-trash)').first().click();
        await confirmDelete(page);
        await expect(page.getByRole('heading', { name: renamed })).toHaveCount(0);
    });

    test('a category without a name is refused', async ({ asRole }) => {
        const page = await asRole(role('manager'));

        await page.goto('/dashboard/categories/create');
        await page.getByRole('button', { name: 'Simpan' }).click();

        await expect(page.getByText(/name field is required/i)).toBeVisible();
        await expect(page).toHaveURL(/\/dashboard\/categories\/create$/);
    });

    test('a cashier cannot open categories', async ({ asRole }) => {
        const page = await asRole(role('cashier'));

        const response = await page.goto('/dashboard/categories');

        expect(response.status()).toBe(403);
    });
});
