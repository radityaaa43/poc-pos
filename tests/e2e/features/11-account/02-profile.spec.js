import { test, expect as baseExpect } from '../../support/fixtures.js';
import { login } from '../../support/login.js';
import { signIn } from '../../support/platform.js';

// Pages here are slow to answer on a shared machine: wait longer than the 15 s default.
const expect = baseExpect.configure({ timeout: 45_000 });
test.use({ actionTimeout: 60_000, navigationTimeout: 60_000 });

// 1x1 PNG.
const PNG = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', 'base64');

test.describe('profile', () => {
    test.setTimeout(240_000);
    test('a user edits their name, email and avatar, and the change survives a reload', async ({ page, arrange, uniq }) => {
        const { email } = arrange('platform.user');
        const other = arrange('platform.user');
        const name = uniq('Nama Baru');
        const newEmail = `${uniq('baru')}@e2e.test`;
        const profile = page.locator('section', { has: page.getByRole('heading', { name: 'Profile Information' }) });

        await signIn(page, email);
        await page.goto('/dashboard/profile');

        // An address that belongs to someone else is refused.
        await profile.getByLabel('Email').fill(other.email);
        await profile.getByRole('button', { name: 'Save' }).click();
        await expect(profile.getByText('The email has already been taken.')).toBeVisible();

        await profile.getByLabel('Name').fill(name);
        await profile.getByLabel('Email').fill(newEmail);
        await profile.locator('input[type=file]').setInputFiles({ name: 'avatar.png', mimeType: 'image/png', buffer: PNG });
        await profile.getByRole('button', { name: 'Save' }).click();
        await expect(profile.getByText('Saved.')).toBeVisible();

        await page.reload();
        await expect(profile.getByLabel('Name')).toHaveValue(name);
        await expect(profile.getByLabel('Email')).toHaveValue(newEmail);
        await expect(profile.locator('img[src*="avatars"]')).toBeVisible();
    });

    test('a user changes their password and signs in with the new one', async ({ page, arrange }) => {
        const { email } = arrange('platform.user');
        const form = page.locator('section', { has: page.getByRole('heading', { name: 'Update Password' }) });

        await signIn(page, email);
        await page.goto('/dashboard/profile');

        await form.getByLabel('Current Password').fill('not-my-password');
        await form.getByLabel('New Password').fill('fresh-password-1');
        await form.getByLabel('Confirm Password').fill('fresh-password-1');
        await form.getByRole('button', { name: 'Save' }).click();
        await expect(form.getByText('The password is incorrect.')).toBeVisible();

        // A failed save clears the fields: fill them again each time.
        await form.getByLabel('Current Password').fill('password');
        await form.getByLabel('New Password').fill('fresh-password-1');
        await form.getByLabel('Confirm Password').fill('different-password');
        await form.getByRole('button', { name: 'Save' }).click();
        await expect(form.getByText('The password field confirmation does not match.')).toBeVisible();

        await form.getByLabel('Current Password').fill('password');
        await form.getByLabel('New Password').fill('fresh-password-1');
        await form.getByLabel('Confirm Password').fill('fresh-password-1');
        await form.getByRole('button', { name: 'Save' }).click();
        await expect(form.getByText('Saved.')).toBeVisible();

        await page.context().clearCookies();
        await login(page, email, 'password');
        await expect(page.getByText('These credentials do not match our records.')).toBeVisible();
        await login(page, email, 'fresh-password-1');
        await expect(page).toHaveURL(/\/dashboard/);
    });

    test('a user deletes their own account after confirming the password', async ({ page, arrange }) => {
        const { email } = arrange('platform.user');

        await signIn(page, email);
        await page.goto('/dashboard/profile');
        await page.getByRole('button', { name: 'Delete Account' }).click();

        const dialog = page.getByRole('dialog');
        await dialog.getByPlaceholder('Password').fill('wrong-password');
        await dialog.getByRole('button', { name: 'Delete Account' }).click();
        await expect(dialog.getByText('The password is incorrect.')).toBeVisible();

        await dialog.getByPlaceholder('Password').fill('password');
        await dialog.getByRole('button', { name: 'Delete Account' }).click();
        await expect(page).not.toHaveURL(/profile/);

        await login(page, email);
        await expect(page.getByText('These credentials do not match our records.')).toBeVisible();
    });
});
