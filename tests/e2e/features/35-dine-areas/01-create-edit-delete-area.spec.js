import { test, expect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';

const manager = roles.find((role) => role.key === 'manager');

test.describe('35-dine-areas', () => {
    test('create and delete an area', async ({ asRole, uniq }) => {
        const page = await asRole(manager);
        const name = uniq('Area');

        await page.goto('/dashboard/dine-areas');
        await expect(page.getByText(/area terdaftar/)).toBeVisible();

        await page.getByRole('button', { name: 'Tambah Area' }).first().click();
        const dialog = page.getByRole('dialog', { name: 'Tambah Area' });
        await dialog.getByPlaceholder('Contoh: Indoor, Outdoor, VIP').fill(name);
        await dialog.getByRole('button', { name: 'Simpan' }).click();
        const row = page.getByRole('row').filter({ hasText: name });
        await expect(row).toContainText('0 meja');
        await expect(row).toContainText('Aktif');

        // Delete (native confirm)
        page.once('dialog', (confirm) => confirm.accept());
        await row.locator('button').last().click();
        await expect(row).toHaveCount(0);
    });

    // Bug #210: the pencil is an Inertia Link without href, so the click also GETs the page and the
    // response closes the edit dialog about half a second after it opened, wiping what the user typed.
    test.fixme('rename and deactivate an area', async ({ asRole, arrange, uniq }) => {
        const { area_name } = arrange('dine.table', { area: uniq('Area'), name: uniq('M') });
        const renamed = `${area_name}-baru`;
        const page = await asRole(manager);

        await page.goto('/dashboard/dine-areas');
        const row = page.getByRole('row').filter({ hasText: area_name });
        await row.locator('a, button').first().click();
        const dialog = page.getByRole('dialog', { name: 'Edit Area' });
        await dialog.getByPlaceholder('Contoh: Indoor, Outdoor, VIP').fill(renamed);
        await dialog.getByLabel('Area aktif').setChecked(false);
        await dialog.getByRole('button', { name: 'Perbarui' }).click();

        const renamedRow = page.getByRole('row').filter({ hasText: renamed });
        await expect(renamedRow).toContainText('Nonaktif');
        await expect(page.getByRole('row').filter({ hasText: area_name }).filter({ hasNotText: renamed })).toHaveCount(0);
    });
});
