import { test, expect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';
import { roleOf } from '../../support/crm.js';

test.describe('pricing rules: roles', () => {
    test('a cashier cannot open promo pages, a manager can', async ({ asRole }) => {
        const cashier = await asRole(roleOf(roles, 'cashier'));
        for (const path of ['/dashboard/pricing-rules', '/dashboard/pricing-rules/create']) {
            const response = await cashier.goto(path);
            expect(response.status()).toBe(403);
        }

        const manager = await asRole(roleOf(roles, 'manager'));
        await manager.goto('/dashboard/pricing-rules');
        await expect(manager.getByRole('heading', { name: 'Promo Harga' }).first()).toBeVisible();
        await expect(manager.getByRole('link', { name: 'Buat Rule' })).toBeVisible();
    });
});
