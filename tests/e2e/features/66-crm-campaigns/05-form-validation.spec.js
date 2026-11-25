import { test, expect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';
import { roleOf } from '../../support/crm.js';

test.describe('CRM campaigns: form validation', () => {
    // Bug #144: the form never renders `errors`, so a refused submit shows nothing.
    test.fixme('an empty campaign name is reported to the user', async ({ asRole }) => {
        const page = await asRole(roleOf(roles, 'manager'));
        await page.goto('/dashboard/crm-campaigns/create');
        await page.getByRole('button', { name: 'Simpan Campaign' }).click();

        await expect(page.getByText(/name field is required/i)).toBeVisible();
        await expect(page).toHaveURL(/crm-campaigns\/create$/);
    });
});
