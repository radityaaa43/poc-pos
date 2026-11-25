import { test, expect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';

const manager = roles.find((role) => role.key === 'manager');

async function submitBlankName(page) {
    await page.goto('/dashboard/dine-areas');
    await page.getByRole('button', { name: 'Tambah Area' }).first().click();
    const dialog = page.getByRole('dialog');
    // Spaces pass the browser's `required` check; the server trims them to empty.
    await dialog.getByPlaceholder('Contoh: Indoor, Outdoor, VIP').fill('   ');
    await dialog.getByRole('button', { name: 'Simpan' }).click();

    return dialog;
}

test.describe('35-dine-areas', () => {
    test('a blank area name is refused: the user is told, the form stays open, nothing is created', async ({ asRole, arrange }) => {
        const page = await asRole(manager);

        const dialog = await submitBlankName(page);
        await expect(page.getByText('Gagal menyimpan area.')).toBeVisible();
        await expect(dialog).toBeVisible();

        await dialog.getByRole('button', { name: 'Batal' }).click();
        // Own state, not a row count: other steps create areas in the shared e2e database.
        expect(arrange('dine.blank-areas').count).toBe(0);
    });

    test('the blank-name error is shown next to the field', async ({ asRole }) => {
        const dialog = await submitBlankName(await asRole(manager));
        await expect(dialog.getByText(/name field is required/i)).toBeVisible();
    });
});
