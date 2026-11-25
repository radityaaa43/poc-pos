import { test, expect as baseExpect } from '../../support/fixtures.js';
import { login } from '../../support/login.js';

// Pages here are slow to answer on a shared machine: wait longer than the 15 s default.
const expect = baseExpect.configure({ timeout: 45_000 });
test.use({ actionTimeout: 60_000, navigationTimeout: 60_000 });

// MAIL_MAILER=log: the reset mail lands in the log, platform.reset-link reads the link out of it.
test.describe('password reset', () => {
    test.setTimeout(240_000);
    test('a user asks for a link, sets a new password with it, and the link works once', async ({ page, arrange }) => {
        const { email } = arrange('platform.user');
        const sendLink = async (address) => {
            await page.goto('/forgot-password');
            await page.getByPlaceholder('nama@email.com').fill(address);
            await page.getByRole('button', { name: 'Kirim Link Reset' }).click();
        };

        // An unknown address is refused with a message.
        await sendLink('nobody@e2e.test');
        await expect(page.getByText("We can't find a user with that email address.")).toBeVisible();

        await sendLink(email);
        await expect(page.getByText('We have emailed your password reset link.')).toBeVisible();

        const { path } = arrange('platform.reset-link', { email });
        await page.goto(path);
        await expect(page.getByLabel('Email')).toHaveValue(email);

        // The two passwords must match.
        await page.getByLabel('Password', { exact: true }).fill('new-password-1');
        await page.getByLabel('Confirm Password').fill('another-password');
        await page.getByRole('button', { name: 'Reset Password' }).click();
        await expect(page.getByText('The password field confirmation does not match.')).toBeVisible();

        await page.getByLabel('Confirm Password').fill('new-password-1');
        await page.getByRole('button', { name: 'Reset Password' }).click();
        await expect(page).toHaveURL(/\/login$/);
        await expect(page.getByText('Your password has been reset.')).toBeVisible();

        // The old password is dead, the new one signs in.
        await login(page, email, 'password');
        await expect(page.getByText('These credentials do not match our records.')).toBeVisible();
        await login(page, email, 'new-password-1');
        await expect(page).toHaveURL(/\/dashboard/);

        // The same link cannot be used a second time.
        await page.context().clearCookies();
        await page.goto(path);
        await page.getByLabel('Password', { exact: true }).fill('third-password-1');
        await page.getByLabel('Confirm Password').fill('third-password-1');
        await page.getByRole('button', { name: 'Reset Password' }).click();
        await expect(page.getByText('This password reset token is invalid.')).toBeVisible();
    });
});
