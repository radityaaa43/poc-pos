import { test, expect as baseExpect } from '../../support/fixtures.js';
import { signIn } from '../../support/platform.js';

// Pages here are slow to answer on a shared machine: wait longer than the 15 s default.
const expect = baseExpect.configure({ timeout: 45_000 });
test.use({ actionTimeout: 60_000, navigationTimeout: 60_000 });

// A warehouse made here is removed again: it would show up in every other step's warehouse pickers.
test.describe('warehouses', () => {
    test.setTimeout(240_000);

    const row = (page, title) => page.locator('div.p-4.flex').filter({ hasText: title });

    test('a manager adds a warehouse to the outlet, renames it and deletes it', async ({ page, arrange, uniq }) => {
        const { email } = arrange('platform.user');
        const code = uniq('wh').toUpperCase().slice(0, 12);
        const name = uniq('Gudang');

        await signIn(page, email);
        await page.goto('/dashboard/settings/warehouses');

        await page.getByRole('button', { name: 'Tambah Gudang' }).click();
        await page.getByRole('button', { name: 'Simpan' }).click();
        await expect(page.getByText('The code field is required.')).toBeVisible();
        await expect(page.getByText('The name field is required.')).toBeVisible();

        // The seeded code is taken.
        await page.getByPlaceholder('WH-002').fill('WH-E2E');
        await page.getByPlaceholder('Gudang Cabang A').fill(name);
        await page.getByRole('button', { name: 'Simpan' }).click();
        await expect(page.getByText('The code has already been taken.')).toBeVisible();

        await page.getByPlaceholder('WH-002').fill(code);
        await page.getByRole('button', { name: 'Simpan' }).click();
        await expect(row(page, name).getByText(code)).toBeVisible();

        // The edit form keeps the code fixed.
        await row(page, name).getByRole('button').first().click();
        await expect(page.getByPlaceholder('WH-002')).toBeDisabled();
        await page.getByRole('button', { name: 'Batal' }).click();

        // No stock and no history: it can go.
        page.once('dialog', (dialog) => dialog.accept());
        await row(page, name).getByRole('button').last().click();
        await expect(row(page, name)).toBeHidden();
    });

    // Bug #142: the page gets only id, code, name and active flag; saving an edit fails with "The type field is
    // required.", type, phone and order are blank, and inactive rows vanish.
    test.fixme('the list shows each warehouse type, phone and order, and keeps inactive ones', async ({ page, arrange, uniq }) => {
        const { email } = arrange('platform.user');
        const code = uniq('wh').toUpperCase().slice(0, 12);
        const name = uniq('Gudang');

        await signIn(page, email);
        await page.goto('/dashboard/settings/warehouses');
        await page.getByRole('button', { name: 'Tambah Gudang' }).click();
        await page.getByPlaceholder('WH-002').fill(code);
        await page.getByPlaceholder('Gudang Cabang A').fill(name);
        await page.getByPlaceholder('021-12345678').fill('021-5550123');
        await page.getByRole('button', { name: 'Simpan' }).click();

        await expect(row(page, name).getByText('Cabang', { exact: true })).toBeVisible();
        await expect(row(page, name).getByText(/021-5550123/)).toBeVisible();

        await row(page, name).getByRole('button').first().click();
        await page.getByPlaceholder('Gudang Cabang A').fill(`${name} baru`);
        await page.getByLabel('Aktif').uncheck();
        await page.getByRole('button', { name: 'Update' }).click();
        await expect(row(page, `${name} baru`)).toHaveClass(/opacity-50/);

        page.once('dialog', (dialog) => dialog.accept());
        await row(page, `${name} baru`).getByRole('button').last().click();
        await expect(row(page, `${name} baru`)).toBeHidden();
    });
});
