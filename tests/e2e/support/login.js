import { PASSWORD } from './roles.js';

// The login form has no label/id association, so fields are found by placeholder and name.
export async function login(page, email, password = PASSWORD) {
    await page.goto('/login');
    await page.getByPlaceholder('nama@email.com').fill(email);
    await page.locator('input[name=password]').fill(password);
    await page.getByRole('button', { name: 'Masuk' }).click();
}
