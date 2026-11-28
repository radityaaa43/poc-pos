import { test, expect } from '../../support/fixtures.js';
import { role } from '../../support/inventory.js';

// REQ-009 AC-21 (reservations): without dine-reservations-manage there is no menu entry and the server refuses.
test.describe('81-dine-reservations: without the permission', () => {
    test('@REQ-009-AC-21 a cashier has no Reservasi menu and the page answers 403', async ({ asRole }) => {
        const page = await asRole(role('cashier'));

        await page.goto('/dashboard/access');
        const sidebar = page.locator('nav.dashboard-scrollbar');
        await expect(sidebar.getByRole('link', { name: 'Pesanan Dine-In' })).toBeVisible();
        await expect(sidebar.getByRole('link', { name: 'Reservasi' })).toHaveCount(0);

        const response = await page.goto('/dashboard/dine-reservations');
        expect(response.status()).toBe(403);
    });
});
