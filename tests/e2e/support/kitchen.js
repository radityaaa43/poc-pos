import { expect } from '@playwright/test';
import { confirmPassword, signIn } from './platform.js';
import { roles } from './roles.js';

const manager = roles.find((role) => role.key === 'manager');

// A manager confirms that a pending bank transfer arrived, in a fresh browser context: the password step-up must
// not leak into the shared saved session of the manager role. Returns once the receipt says "Lunas".
export async function confirmTransfer(browser, invoice) {
    const context = await browser.newContext({ locale: 'id-ID', serviceWorkers: 'block' });
    const page = await context.newPage();
    await signIn(page, manager.email);
    await confirmPassword(page);
    await page.goto(`/dashboard/transactions/${invoice}/print`);
    await page.getByRole('button', { name: 'Konfirmasi Bayar' }).click();
    await page.getByRole('button', { name: 'Konfirmasi Lunas' }).click();
    await expect(page.getByText('Lunas', { exact: true })).toBeVisible();
    await context.close();
}
