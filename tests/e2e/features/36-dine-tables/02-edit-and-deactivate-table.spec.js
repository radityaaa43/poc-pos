import { test, expect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';

const manager = roles.find((role) => role.key === 'manager');

test.describe('36-dine-tables', () => {
    test('edit a table from the floor plan, then deactivate it: the guest link stops working', async ({ asRole, arrange, uniq }) => {
        const { table_name, token } = arrange('dine.table', { area: uniq('Area'), name: uniq('M') });
        const renamed = `${table_name}-b`;
        const page = await asRole(manager);

        await page.goto('/dashboard/dine-tables');
        await page.getByRole('button', { name: `${table_name}, aktif` }).click();
        await page.getByRole('button', { name: 'Edit' }).click();
        const dialog = page.getByRole('dialog');
        await expect(dialog.getByText(`Edit Meja: ${table_name}`)).toBeVisible();
        await dialog.getByPlaceholder('Contoh: M1, Outdoor-1').fill(renamed);
        await dialog.locator('input[type=number]').first().fill('8');
        await dialog.getByRole('button', { name: 'Perbarui' }).click();
        await expect.poll(() => arrange('dine.table-state', { name: renamed })).toMatchObject({ exists: true, capacity: 8, token });

        // Active table: guest menu loads. Deactivated: 404.
        const guest = await page.context().newPage();
        expect((await guest.goto(`/dine/${token}`)).status()).toBe(200);

        await page.getByRole('button', { name: `${renamed}, aktif` }).click();
        await page.getByRole('button', { name: 'Edit' }).click();
        await dialog.getByLabel('Meja aktif').uncheck();
        await dialog.getByRole('button', { name: 'Perbarui' }).click();
        await expect(page.getByRole('button', { name: `${renamed}, nonaktif` })).toBeVisible();

        expect((await guest.goto(`/dine/${token}`)).status()).toBe(404);
    });

    async function submitBlankName(page) {
        await page.goto('/dashboard/dine-tables');
        await page.getByRole('button', { name: 'Tambah Meja' }).first().click();
        const dialog = page.getByRole('dialog');
        // Spaces pass the browser's `required` check; the server trims them to empty.
        await dialog.getByPlaceholder('Contoh: M1, Outdoor-1').fill('   ');
        await dialog.getByRole('button', { name: 'Simpan' }).click();

        return dialog;
    }

    test('a blank table name is refused: the user is told and the form stays open', async ({ asRole }) => {
        const page = await asRole(manager);
        const dialog = await submitBlankName(page);

        await expect(page.getByText('Gagal menyimpan meja.')).toBeVisible();
        await expect(dialog).toBeVisible();
    });

    test('the blank-name error is shown next to the field', async ({ asRole }) => {
        const dialog = await submitBlankName(await asRole(manager));
        await expect(dialog.getByText(/name field is required/i)).toBeVisible();
    });
});
