import { test, expect as baseExpect } from '../../support/fixtures.js';
import { confirmPassword, signIn } from '../../support/platform.js';

// Pages here are slow to answer on a shared machine: wait longer than the 15 s default.
const expect = baseExpect.configure({ timeout: 45_000 });
test.use({ actionTimeout: 60_000, navigationTimeout: 60_000 });

test.describe('users', () => {
    test.setTimeout(240_000);

    // The card of one user in the list (the smallest block that holds the name and the Edit link).
    const userCard = (page, name) => page.locator('div')
        .filter({ has: page.getByRole('heading', { name, level: 3 }) })
        .filter({ has: page.getByRole('link', { name: 'Edit' }) })
        .last();
    const search = async (page, text) => {
        await page.getByPlaceholder('Cari pengguna...').fill(text);
        await page.getByPlaceholder('Cari pengguna...').press('Enter');
    };

    test('an admin creates a user, changes their role, and the user can sign in', async ({ page, arrange, uniq }) => {
        const admin = arrange('platform.user', { role: 'super-admin' });
        const name = uniq('Kasir Baru');
        const email = `${uniq('kasir')}@e2e.test`;

        await signIn(page, admin.email);
        await confirmPassword(page);

        // Name, email, password and a role are required.
        await page.goto('/dashboard/users/create');
        await page.getByRole('button', { name: 'Simpan' }).click();
        await expect(page.getByText('The name field is required.')).toBeVisible();
        await expect(page.getByText('The email field is required.')).toBeVisible();
        await expect(page.getByText('The password field is required.')).toBeVisible();

        // A taken address and a mismatching confirmation are refused.
        await page.getByPlaceholder('Masukkan nama').fill(name);
        await page.getByPlaceholder('email@example.com').fill('cashier@e2e.test');
        await page.getByPlaceholder('Minimal 8 karakter').fill('password');
        await page.getByPlaceholder('Ulangi kata sandi').fill('different');
        await page.getByRole('checkbox', { name: 'cashier', exact: true }).check();
        await page.getByRole('button', { name: 'Simpan' }).click();
        await expect(page.getByText('The email has already been taken.')).toBeVisible();
        await expect(page.getByText('The password field confirmation does not match.')).toBeVisible();

        await page.getByPlaceholder('email@example.com').fill(email);
        await page.getByPlaceholder('Ulangi kata sandi').fill('password');
        await page.getByRole('checkbox', { name: 'Outlet E2E (E2E)' }).check();
        await page.getByRole('button', { name: 'Simpan' }).click();
        await expect(page).toHaveURL(/\/dashboard\/users$/);

        // The new user is listed with the cashier role.
        await search(page, name);
        await expect(userCard(page, name).getByText(email)).toBeVisible();
        await expect(userCard(page, name).getByText('cashier', { exact: true })).toBeVisible();

        // Edit: another name and the manager role instead of cashier; the password stays.
        await userCard(page, name).getByRole('link', { name: 'Edit' }).click();
        await expect(page.getByPlaceholder('Nama pengguna')).toHaveValue(name);
        await page.getByPlaceholder('Nama pengguna').fill(`${name} Senior`);
        await page.getByRole('checkbox', { name: 'cashier', exact: true }).uncheck();
        await page.getByRole('checkbox', { name: 'manager', exact: true }).check();
        await page.getByRole('button', { name: 'Simpan Perubahan' }).click();
        await expect(page).toHaveURL(/\/dashboard\/users$/);
        await search(page, name);
        await expect(userCard(page, `${name} Senior`).getByText('manager', { exact: true })).toBeVisible();

        // The user signs in with the password the admin set.
        await page.context().clearCookies();
        await signIn(page, email);
        await expect(page).toHaveURL(/\/dashboard/);
    });

    test('an admin deletes a user', async ({ page, arrange, uniq }) => {
        const admin = arrange('platform.user', { role: 'super-admin' });
        const victim = arrange('platform.user', { name: uniq('Hapus Saya') });

        await signIn(page, admin.email);
        await confirmPassword(page);
        await page.goto('/dashboard/users');
        await search(page, victim.name);
        await userCard(page, victim.name).getByRole('button', { name: 'Hapus' }).click();
        await page.getByRole('button', { name: 'Ya, Hapus!' }).click();
        await expect(page.getByText(victim.email)).toBeHidden();

        // The deleted user can no longer sign in.
        await page.context().clearCookies();
        await page.goto('/login');
        await page.getByPlaceholder('nama@email.com').fill(victim.email);
        await page.locator('input[name=password]').fill('password');
        await page.getByRole('button', { name: 'Masuk' }).click();
        await expect(page.getByText('These credentials do not match our records.')).toBeVisible();
    });

    // Bug #132: the server refuses and flashes an error, but the page renders no flash and Swal says "Berhasil".
    test.fixme('deleting your own account is refused with a visible message', async ({ page, arrange }) => {
        const admin = arrange('platform.user', { role: 'super-admin' });

        await signIn(page, admin.email);
        await confirmPassword(page);
        await page.goto('/dashboard/users');
        await search(page, admin.name);
        await userCard(page, admin.name).getByRole('button', { name: 'Hapus' }).click();
        await page.getByRole('button', { name: 'Ya, Hapus!' }).click();
        await expect(page.getByText('Anda tidak dapat menghapus akun Anda sendiri.')).toBeVisible();
        await expect(page.getByRole('heading', { name: admin.name, level: 3 })).toBeVisible();
    });
});
