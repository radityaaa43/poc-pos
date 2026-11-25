import { test, expect as baseExpect } from '../../support/fixtures.js';
import { signIn } from '../../support/platform.js';

// Pages here are slow to answer on a shared machine: wait longer than the 15 s default.
const expect = baseExpect.configure({ timeout: 45_000 });
test.use({ actionTimeout: 60_000, navigationTimeout: 60_000 });

test.describe('units', () => {
    test.setTimeout(240_000);

    test('a manager adds, renames and deletes a unit', async ({ page, arrange, uniq }) => {
        const { email } = arrange('platform.user');
        const code = uniq('U').toUpperCase().slice(0, 9);
        const name = uniq('Satuan');
        const row = (title) => page.locator('div.p-4.flex').filter({ hasText: title });

        await signIn(page, email);
        await page.goto('/dashboard/settings/units');
        await page.getByRole('button', { name: 'Tambah Satuan' }).click();

        // All three fields are required.
        await page.getByRole('button', { name: 'Simpan' }).click();
        await expect(page.getByText('The code field is required.')).toBeVisible();
        await expect(page.getByText('The name field is required.')).toBeVisible();
        await expect(page.getByText('The symbol field is required.')).toBeVisible();

        // A code that exists is refused (PCS ships with the app).
        await page.getByPlaceholder('PCS', { exact: true }).fill('PCS');
        await page.getByPlaceholder('Pieces').fill(name);
        await page.getByPlaceholder('pcs', { exact: true }).fill('x');
        await page.getByRole('button', { name: 'Simpan' }).click();
        await expect(page.getByText('The code has already been taken.')).toBeVisible();

        await page.getByPlaceholder('PCS', { exact: true }).fill(code);
        await page.getByRole('button', { name: 'Simpan' }).click();
        await expect(row(name).getByText(code, { exact: true })).toBeVisible();

        // Rename; the code and symbol stay.
        await row(name).getByRole('button').first().click();
        await page.getByPlaceholder('Pieces').fill(`${name} baru`);
        await page.getByRole('button', { name: 'Update' }).click();
        await expect(row(`${name} baru`).getByText(code, { exact: true })).toBeVisible();

        page.once('dialog', (dialog) => dialog.accept());
        await row(`${name} baru`).getByRole('button').last().click();
        await expect(row(`${name} baru`)).toBeHidden();
    });
});
