import { test, expect } from '../../support/fixtures.js';
import { role } from '../../support/inventory.js';

test.describe('48-modifiers: permission', () => {
    test('without modifiers-manage the menu is hidden and the server answers 403', { tag: ['@REQ-008-AC-05'] }, async ({ asRole }) => {
        const cashier = await asRole(role('cashier'));

        const response = await cashier.goto('/dashboard/settings/modifiers');
        expect(response.status()).toBe(403);
        await cashier.goto('/dashboard/access');
        await expect(cashier.getByRole('link', { name: 'Pelanggan', exact: true })).toBeVisible();
        await expect(cashier.getByRole('link', { name: 'Modifier', exact: true })).toHaveCount(0);

        const manager = await asRole(role('manager'));
        await manager.goto('/dashboard/access');
        await expect(manager.locator('nav.dashboard-scrollbar').getByRole('link', { name: 'Modifier', exact: true })).toBeVisible();
    });
});
