import { login } from './login.js';
import { PASSWORD } from './roles.js';

// Sensitive actions (roles, users, outlets, payment settings) redirect to /confirm-password until the
// password was confirmed in the last 15 minutes. Confirm up front so the step's own click goes through.
export async function confirmPassword(page, password = PASSWORD) {
    await page.goto('/confirm-password');
    await page.getByPlaceholder('Masukkan password Anda').fill(password);
    await page.getByRole('button', { name: 'Lanjutkan' }).click();
    await page.waitForURL((url) => !url.pathname.includes('confirm-password'));
}

// Dashboard forms put a <label> next to the input without linking them: find the input by its label text.
export const field = (page, label) => page.locator('label', { hasText: label }).first().locator('xpath=..').locator('input, textarea, select').first();

// login() returns before the redirect lands; a step that navigates right after it needs to wait.
export async function signIn(page, email, password = PASSWORD) {
    await login(page, email, password);
    await page.waitForURL(/\/dashboard/);
}
