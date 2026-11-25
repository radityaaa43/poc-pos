import { test, expect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';
import { roleOf } from '../../support/crm.js';

test.describe('pricing rules: validation', () => {
    test('an empty rule shows what is missing', async ({ asRole }) => {
        const manager = await asRole(roleOf(roles, 'manager'));
        await manager.goto('/dashboard/pricing-rules/create');

        await manager.getByRole('button', { name: 'Simpan Rule' }).click();
        await expect(manager.getByText(/name field is required/i)).toBeVisible();
        await expect(manager.getByText(/discount value field is required/i)).toBeVisible();
        await expect(manager).toHaveURL(/pricing-rules\/create$/);
    });
});
