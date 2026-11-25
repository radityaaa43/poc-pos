import { test, expect } from '../../support/fixtures.js';
import { role } from '../../support/inventory.js';

// REQ-006 AC-09, clause 3: every kitchen display starts on "Semua stasiun" until someone picks a station.
test.describe('34-kitchen-stations: default display', () => {
    test('@REQ-006-AC-09 a fresh device shows Semua stasiun with every item', async ({ asRole, arrange, uniq }) => {
        const bar = uniq('Bar');
        const sale = arrange('kitchen-sale', { prefix: uniq('Awal'), flagged: 2, station: bar });

        const page = await asRole(role('cashier')); // a new browser context: nothing saved on this device
        await page.goto('/dashboard/kitchen');

        const stations = page.getByRole('group', { name: 'Stasiun' });
        await expect(stations.getByRole('button', { name: 'Semua stasiun', exact: true })).toHaveAttribute('aria-pressed', 'true');
        await expect(stations.getByRole('button', { name: bar, exact: true })).toHaveAttribute('aria-pressed', 'false');
        await expect(page.locator('article', { hasText: sale.invoice }).getByRole('listitem')).toHaveCount(2);
    });
});
