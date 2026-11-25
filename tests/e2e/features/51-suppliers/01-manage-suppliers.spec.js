import { test, expect as baseExpect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';

const expect = baseExpect.configure({ timeout: 45_000 });

const manager = roles.find((role) => role.key === 'manager');

// One supplier on the page: the card holding its name.
const card = (page, name) => page.locator('div.p-4').filter({ hasText: name });

test.describe('51-suppliers', () => {
    test.describe.configure({ timeout: 240_000 }); // a loaded runner needs more than the default 30 s
    test.use({ actionTimeout: 45_000 });

    test('manage-suppliers: create, edit and delete a supplier', async ({ asRole, uniq }) => {
        const name = uniq('Toko Kopi');
        const page = await asRole(manager);
        await page.goto('/dashboard/suppliers');

        // Fields in the form: name, phone, email, address (labels are not tied to the inputs).
        const form = page.locator('form');
        await form.getByRole('textbox').nth(0).fill(name);
        await form.getByRole('textbox').nth(1).fill('08123456789');
        await form.getByRole('textbox').nth(2).fill('toko@e2e.test');
        await form.getByRole('textbox').nth(3).fill('Jl. Kopi 1');
        await page.getByRole('button', { name: 'Simpan' }).click();

        await expect(card(page, name)).toContainText('08123456789 • toko@e2e.test');
        await expect(card(page, name)).toContainText('Jl. Kopi 1');

        // Edit: the pencil loads the supplier into the form.
        await card(page, name).getByRole('button').first().click();
        await expect(form.getByRole('textbox').nth(0)).toHaveValue(name);
        await form.getByRole('textbox').nth(1).fill('0211234567');
        await page.getByRole('button', { name: 'Update' }).click();

        await expect(card(page, name)).toContainText('0211234567 • toko@e2e.test');

        // Delete: a supplier without payables goes away after the confirmation.
        page.once('dialog', (dialog) => dialog.accept());
        await card(page, name).getByRole('button').last().click();

        await expect(card(page, name)).toHaveCount(0);
    });

    test('manage-suppliers: a supplier with a payable cannot be deleted', async ({ asRole, arrange }) => {
        const { material_id } = arrange('purchasing.material', { purchased_by: 'outlet' });
        const { supplier } = arrange('purchasing.po', { material_id, receive: 1 });
        const page = await asRole(manager);
        await page.goto('/dashboard/suppliers');

        page.once('dialog', (dialog) => dialog.accept());
        const deleted = page.waitForResponse((response) => response.request().method() === 'DELETE');
        await card(page, supplier).getByRole('button').last().click();
        await deleted;

        // Still listed after the server answered (its message is checked in 03-flash-toasts).
        await page.reload();
        await expect(card(page, supplier)).toBeVisible();
    });

    test('manage-suppliers: an email without @ is refused by the form', async ({ asRole, uniq }) => {
        const name = uniq('Toko Salah');
        const page = await asRole(manager);
        await page.goto('/dashboard/suppliers');

        const form = page.locator('form');
        await form.getByRole('textbox').nth(0).fill(name);
        await form.getByRole('textbox').nth(2).fill('bukan-email');
        await page.getByRole('button', { name: 'Simpan' }).click();

        expect(await form.getByRole('textbox').nth(2).evaluate((input) => input.validity.typeMismatch)).toBe(true);
        await expect(card(page, name)).toHaveCount(0);
    });
});
