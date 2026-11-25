import { test, expect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';

const cashier = roles.find((role) => role.key === 'cashier');
const manager = roles.find((role) => role.key === 'manager');

test.describe('32-kitchen-display', () => {
    test('two people tap Mulai buat on the same item: the first wins, the second is told who did it', { tag: ['@REQ-003-AC-23'] }, async ({ asRole, arrange, uniq }) => {
        const sale = arrange('kitchen-sale', { prefix: uniq('Rebut') });

        // Both screens load the item as "Diterima" before either taps.
        const first = await asRole(manager);
        const second = await asRole(cashier);
        let polling = false;
        // Keep the second screen stale on purpose: its 10 s poll would otherwise redraw the item before the tap.
        await second.route('**/dashboard/kitchen', (route) => (route.request().headers()['x-inertia-partial-data'] && !polling ? route.abort() : route.continue()));
        for (const page of [first, second]) {
            await page.goto('/dashboard/kitchen');
            await expect(page.locator('article', { hasText: sale.invoice })).toContainText('Diterima');
        }

        const firstItem = first.locator('article', { hasText: sale.invoice }).getByRole('listitem');
        await firstItem.getByRole('button', { name: 'Mulai buat', exact: true }).click();
        await expect(firstItem).toContainText('Sedang dibuat');

        // The second screen still shows the button and taps it: refused with the first person's name.
        const secondItem = second.locator('article', { hasText: sale.invoice }).getByRole('listitem');
        await expect(secondItem).toContainText('Diterima');
        polling = true;
        await secondItem.getByRole('button', { name: 'Mulai buat', exact: true }).click();
        await expect(second.getByText('Sudah diproses oleh E2E Manager')).toBeVisible();
        await expect(secondItem).toContainText('Sedang dibuat');
        await expect(secondItem.getByRole('button', { name: 'Mulai buat', exact: true })).toHaveCount(0);
    });
});
