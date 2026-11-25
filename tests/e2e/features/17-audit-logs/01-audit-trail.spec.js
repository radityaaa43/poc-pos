import { test, expect as baseExpect } from '../../support/fixtures.js';
import { confirmPassword, signIn } from '../../support/platform.js';

// Pages here are slow to answer on a shared machine: wait longer than the 15 s default.
const expect = baseExpect.configure({ timeout: 45_000 });
test.use({ actionTimeout: 60_000, navigationTimeout: 60_000 });

test.describe('audit logs', () => {
    test.setTimeout(240_000);

    test('admin changes leave entries that can be filtered and opened', async ({ page, arrange, uniq }) => {
        const admin = arrange('platform.user', { role: 'super-admin' });
        const known = arrange('platform.user');
        const name = uniq('Jejak Audit');
        const email = `${uniq('jejak')}@e2e.test`;
        const rows = page.getByRole('table').getByRole('row');

        // A guest asks for a password reset: logged with the address as target.
        await page.goto('/forgot-password');
        await page.getByPlaceholder('nama@email.com').fill(known.email);
        await page.getByRole('button', { name: 'Kirim Link Reset' }).click();
        await expect(page.getByText('We have emailed your password reset link.')).toBeVisible();

        // An admin creates a user: logged with before/after details.
        await signIn(page, admin.email);
        await confirmPassword(page);
        await page.goto('/dashboard/users/create');
        await page.getByPlaceholder('Masukkan nama').fill(name);
        await page.getByPlaceholder('email@example.com').fill(email);
        await page.getByPlaceholder('Minimal 8 karakter').fill('password');
        await page.getByPlaceholder('Ulangi kata sandi').fill('password');
        await page.getByRole('checkbox', { name: 'cashier', exact: true }).check();
        await page.getByRole('button', { name: 'Simpan' }).click();
        await expect(page).toHaveURL(/\/dashboard\/users$/);

        await page.goto('/dashboard/audit-logs');
        const search = page.getByPlaceholder('Cari target atau deskripsi');

        // Keyword: the user entry names the new user, the reset entry names the address.
        await search.fill(name);
        await search.press('Enter');
        await expect(rows.filter({ hasText: 'user.created' })).toHaveCount(1);
        await expect(rows.filter({ hasText: admin.name })).toHaveCount(1);
        await search.fill(known.email);
        await search.press('Enter');
        await expect(rows.filter({ hasText: 'auth.password_reset_requested' })).toHaveCount(1);

        // The event and module pickers offer what was logged and narrow the list.
        await page.getByRole('main').getByRole('combobox').nth(2).selectOption('user.created');
        await expect(page).toHaveURL(/event=user\.created/);
        await expect(rows.filter({ hasText: 'auth.password_reset_requested' })).toHaveCount(0);
        await search.fill(name);
        await search.press('Enter');
        await expect(rows.filter({ hasText: 'user.created' })).toHaveCount(1);

        // The actor picker: only this admin's entries.
        await page.getByRole('main').getByRole('combobox').nth(0).selectOption({ label: admin.name });
        await expect(rows.filter({ hasText: 'user.created' })).toHaveCount(1);

        // The detail page shows who, what, and the saved values.
        await rows.filter({ hasText: 'user.created' }).getByRole('link', { name: 'Detail' }).click();
        await expect(page.getByRole('heading', { name: 'Detail Audit Log' })).toBeVisible();
        await expect(page.getByText('Event user.created pada modul users.')).toBeVisible();
        await expect(page.getByText(admin.email)).toBeVisible();
        await expect(page.getByText('Pengguna baru dibuat.')).toBeVisible();
        await expect(page.getByRole('heading', { name: 'After' }).locator('xpath=..').getByText(email)).toBeVisible();
        await expect(page.getByRole('heading', { name: 'Meta' })).toBeVisible();
    });

    test('a bank account that is added and removed is logged', async ({ page, arrange, uniq }) => {
        const admin = arrange('platform.user', { role: 'super-admin' });
        const bank = uniq('Bank');

        await signIn(page, admin.email);
        await confirmPassword(page);

        // A bank account added and removed leaves a created and a deleted entry.
        await page.goto('/dashboard/settings/bank-accounts/create');
        await page.getByPlaceholder('BCA, Mandiri, BNI...').fill(bank);
        await page.getByPlaceholder('1234567890').fill('1122334455');
        await page.getByPlaceholder('Nama pemilik rekening').fill('PT Audit');
        await page.getByRole('button', { name: 'Simpan' }).click();
        await expect(page).toHaveURL(/\/settings\/bank-accounts$/);
        page.once('dialog', (dialog) => dialog.accept());
        await page.locator('div.p-4.flex').filter({ hasText: bank }).getByRole('button').last().click();
        await expect(page.getByText(bank, { exact: true })).toBeHidden();

        await page.goto('/dashboard/audit-logs');
        const search = page.getByPlaceholder('Cari target atau deskripsi');
        await search.fill(bank);
        await search.press('Enter');
        const rows = page.getByRole('table').getByRole('row');
        await expect(rows.filter({ hasText: 'bank_account.created' })).toHaveCount(1);
        await expect(rows.filter({ hasText: 'bank_account.deleted' })).toHaveCount(1);
    });
});
