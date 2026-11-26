import { test, expect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';

const cashier = roles.find((role) => role.key === 'cashier');
const manager = roles.find((role) => role.key === 'manager');

test.describe('32-kitchen-display', () => {
    test('two people tap Serahkan on the same ready item: the first wins, the second is told who did it', { tag: ['@REQ-003-AC-23'] }, async ({ asRole, arrange, uniq }) => {
        const sale = arrange('kitchen-sale', { prefix: uniq('Rebut') });

        // The manager takes the item to Siap; then both screens load it as ready before either hands it over.
        const first = await asRole(manager);
        const second = await asRole(cashier);
        let polling = false;
        // Keep the second screen stale on purpose: its 5 s poll would otherwise redraw the item before the tap.
        await second.route('**/dashboard/kitchen', (route) => (route.request().headers()['x-inertia-partial-data'] && !polling ? route.abort() : route.continue()));
        await first.goto('/dashboard/kitchen');
        const firstItem = first.locator('article', { hasText: sale.invoice }).getByRole('listitem');
        await firstItem.getByRole('button', { name: 'Mulai buat', exact: true }).click();
        await expect(firstItem).toContainText('Sedang dibuat');
        await firstItem.getByRole('button', { name: 'Siap', exact: true }).click();
        await expect(firstItem.getByRole('button', { name: 'Serahkan', exact: true })).toBeVisible();
        await second.goto('/dashboard/kitchen');
        const secondItem = second.locator('article', { hasText: sale.invoice }).getByRole('listitem');
        await expect(secondItem).toContainText('Siap');

        await firstItem.getByRole('button', { name: 'Serahkan', exact: true }).click();
        await expect(first.locator('article', { hasText: sale.invoice })).toHaveCount(0);

        // The second screen still shows the button and taps it: refused with the first person's name.
        await expect(secondItem.getByRole('button', { name: 'Serahkan', exact: true })).toBeVisible();
        polling = true;
        await secondItem.getByRole('button', { name: 'Serahkan', exact: true }).click();
        await expect(second.getByText('Sudah diproses oleh E2E Manager')).toBeVisible();
        await expect(second.locator('article', { hasText: sale.invoice })).toHaveCount(0);
    });
});
