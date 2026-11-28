import { test, expect } from '../../support/fixtures.js';
import { role } from '../../support/inventory.js';

test.describe('82-stock-waste', () => {
    test('without stock-waste-create the menu is hidden and the server answers 403', { tag: ['@REQ-011-AC-13'] }, async ({ asRole }) => {
        const page = await asRole(role('cashier'));

        await page.goto('/dashboard/access');
        const sidebar = page.locator('nav.dashboard-scrollbar');
        await expect(sidebar).toContainText('Transaksi');
        await expect(sidebar.getByRole('link', { name: 'Waste', exact: true })).toHaveCount(0);

        expect((await page.goto('/dashboard/stock-waste/create')).status()).toBe(403);
        // The store route is refused before validation: send the CSRF cookie so 403 is the permission's answer.
        const token = (await page.context().cookies()).find((cookie) => cookie.name === 'XSRF-TOKEN');
        const response = await page.request.post('/dashboard/stock-waste', { headers: { 'X-XSRF-TOKEN': decodeURIComponent(token.value) }, maxRedirects: 0 });
        expect(response.status()).toBe(403);
    });
});
