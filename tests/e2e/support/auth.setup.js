import { test as setup, expect } from './fixtures.js';
import { login } from './login.js';
import { roles, authFile } from './roles.js';

for (const role of roles) {
    setup(`log in as ${role.key}`, async ({ page }) => {
        await login(page, role.email);
        await expect(page).toHaveURL(new RegExp(`${role.landing}$`));
        await page.context().storageState({ path: authFile(role) });
    });
}
