import { test, expect } from '../../support/fixtures.js';
import { role, confirmDelete } from '../../support/inventory.js';

test.describe('43-raw-materials', () => {
    test('editing a raw material renames it and locks its stock unit', async ({ asRole, uniq }) => {
        const page = await asRole(role('manager'));
        const title = uniq('Susu');
        const renamed = `${title}-v2`;

        await page.goto('/dashboard/raw-materials/create');
        await page.getByLabel('Nama Bahan').fill(title);
        await page.getByLabel('Dibeli Oleh').selectOption('outlet');
        await page.getByLabel('Satuan Stok').selectOption({ label: 'PCS' });
        await page.getByRole('button', { name: 'Simpan' }).click();
        await expect(page).toHaveURL(/\/dashboard\/raw-materials$/);

        await page.goto(`/dashboard/raw-materials?search=${title}`);
        await page.getByRole('row').filter({ hasText: title }).locator('a[href$="/edit"]').click();
        // Stock is counted in this unit, so it stays.
        await expect(page.getByLabel('Satuan Stok')).toBeDisabled();
        await page.getByLabel('Nama Bahan').fill(renamed);
        await page.getByLabel('Dibeli Oleh').selectOption('central');
        await page.getByRole('button', { name: 'Simpan' }).click();
        await expect(page).toHaveURL(/\/dashboard\/raw-materials$/);

        await page.goto(`/dashboard/raw-materials?search=${renamed}`);
        const row = page.getByRole('row').filter({ hasText: renamed });
        await expect(row).toContainText('Pusat');
    });

    test('a material that is not used anywhere can be deleted, one in a recipe cannot', async ({ asRole, arrange, uniq }) => {
        const page = await asRole(role('manager'));
        const prefix = uniq('Hapus');
        const { bean } = arrange('inventory.recipe', { prefix });

        // Used as a recipe component: refused with the reason.
        await page.goto(`/dashboard/raw-materials?search=${prefix}`);
        await page.getByRole('button', { name: `Hapus ${bean}` }).click();
        await confirmDelete(page);
        await expect(page.getByText('Bahan baku tidak bisa dihapus karena dipakai sebagai komponen resep.')).toBeVisible();
        await expect(page.getByRole('row').filter({ hasText: bean })).toBeVisible();

        // Not used: gone.
        const free = uniq('Bebas');
        await page.goto('/dashboard/raw-materials/create');
        await page.getByLabel('Nama Bahan').fill(free);
        await page.getByLabel('Dibeli Oleh').selectOption('outlet');
        await page.getByLabel('Satuan Stok').selectOption({ label: 'PCS' });
        await page.getByRole('button', { name: 'Simpan' }).click();
        await expect(page).toHaveURL(/\/dashboard\/raw-materials$/);
        await page.goto(`/dashboard/raw-materials?search=${free}`);
        await page.getByRole('button', { name: `Hapus ${free}` }).click();
        await confirmDelete(page);
        await expect(page.getByText('Bahan baku berhasil dihapus')).toBeVisible();
        await expect(page.getByRole('row').filter({ hasText: free })).toHaveCount(0);
    });
});
