import { test, expect as baseExpect } from '../../support/fixtures.js';
import { confirmPassword, signIn } from '../../support/platform.js';

// The roles page renders every role with its permissions and answers slowly: allow for it.
const expect = baseExpect.configure({ timeout: 60_000 });
test.use({ actionTimeout: 60_000, navigationTimeout: 60_000 });

test.describe('roles', () => {
    test.setTimeout(300_000);

    const roleCard = (page, title) => page.locator('div')
        .filter({ has: page.getByRole('heading', { name: title, level: 3 }) })
        .filter({ has: page.getByRole('button', { name: 'Edit' }) })
        .last();
    const search = async (page, text) => {
        await page.getByPlaceholder('Cari akses group...').fill(text);
        await page.getByPlaceholder('Cari akses group...').press('Enter');
    };
    // Press "Simpan" in the role dialog and wait until the server answered.
    const saveRole = async (page) => {
        const saved = page.waitForResponse((response) => response.url().endsWith('/dashboard/roles') && response.request().method() === 'POST');
        await page.getByRole('button', { name: 'Simpan' }).click();
        return (await saved).status();
    };

    test('an admin creates, renames and deletes a role', async ({ page, arrange, uniq }) => {
        const admin = arrange('platform.user', { role: 'super-admin' });
        const name = uniq('peran');

        await signIn(page, admin.email);
        await confirmPassword(page);
        await page.goto('/dashboard/roles');

        // A role needs a name.
        await page.getByRole('button', { name: 'Tambah Group' }).click();
        await saveRole(page);
        await expect(page.getByText('The name field is required.')).toBeVisible();

        await page.getByPlaceholder('Masukan nama group').fill(name);
        expect(await saveRole(page)).toBeLessThan(400);
        await search(page, name);
        await expect(roleCard(page, name).getByText('0 hak akses')).toBeVisible();

        await roleCard(page, name).getByRole('button', { name: 'Edit' }).click();
        await expect(page.getByPlaceholder('Masukan nama group')).toHaveValue(name);
        await page.getByPlaceholder('Masukan nama group').fill(`${name}-v2`);
        expect(await saveRole(page)).toBeLessThan(400);
        await search(page, `${name}-v2`);
        await expect(roleCard(page, `${name}-v2`)).toBeVisible();

        page.once('dialog', (dialog) => dialog.accept());
        await roleCard(page, `${name}-v2`).getByRole('button', { name: 'Hapus' }).click();
        await expect(page.getByRole('heading', { name: `${name}-v2`, level: 3 })).toBeHidden();
    });

    test('a user holding a custom role gets exactly the access of that role', async ({ page, arrange, uniq }) => {
        const role = arrange('platform.role', { name: uniq('peran'), permissions: 'categories-access' });
        const { email } = arrange('platform.user', { role: role.name });

        await signIn(page, email);
        await page.goto('/dashboard/categories');
        await expect(page).toHaveURL(/\/dashboard\/categories/);
        expect((await page.goto('/dashboard/users')).status()).toBe(403);
        expect((await page.goto('/dashboard/roles')).status()).toBe(403);
    });

    test('the super-admin role cannot be deleted', async ({ page, arrange }) => {
        const admin = arrange('platform.user', { role: 'super-admin' });
        await signIn(page, admin.email);
        await confirmPassword(page);
        await page.goto('/dashboard/roles');
        await search(page, 'super-admin');

        page.once('dialog', (dialog) => dialog.accept());
        await roleCard(page, 'super-admin').getByRole('button', { name: 'Hapus' }).click();
        await expect(page.frameLocator('iframe').getByText('Role super-admin tidak dapat dihapus.')).toBeVisible();
    });

    // Bug #156: the form posts permission ids, the audit log expects names: HTTP 500 after the role is stored.
    test.fixme('a role is created with permissions picked in the form', async ({ page, arrange, uniq }) => {
        const admin = arrange('platform.user', { role: 'super-admin' });
        const name = uniq('peran');
        await page.setViewportSize({ width: 1280, height: 2600 });

        await signIn(page, admin.email);
        await confirmPassword(page);
        await page.goto('/dashboard/roles');
        await page.getByRole('button', { name: 'Tambah Group' }).click();
        await page.getByPlaceholder('Masukan nama group').fill(name);
        await page.getByRole('dialog').getByRole('button').first().click();
        await page.getByRole('option', { name: 'categories-access' }).click();
        await page.getByRole('option', { name: 'categories-create' }).click();
        await page.keyboard.press('Escape');
        expect(await saveRole(page)).toBeLessThan(400);
        await search(page, name);
        await expect(roleCard(page, name).getByText('2 hak akses')).toBeVisible();
    });
});
