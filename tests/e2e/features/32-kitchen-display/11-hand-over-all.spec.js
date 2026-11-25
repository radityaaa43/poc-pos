import { test, expect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';

const cashier = roles.find((role) => role.key === 'cashier');

test.describe('32-kitchen-display', () => {
    test('Serahkan semua serves only the ready items of an order: the item still being made stays in the queue', { tag: ['@REQ-003-AC-09'] }, async ({ asRole, arrange, uniq }) => {
        const sale = arrange('kitchen-sale', { prefix: uniq('Semua'), flagged: 2 });
        const other = arrange('kitchen-sale', { prefix: uniq('Lain'), flagged: 1 });

        const page = await asRole(cashier);
        await page.goto('/dashboard/kitchen');
        const card = page.locator('article', { hasText: sale.invoice });
        const ready = card.getByRole('listitem').filter({ hasText: sale.flagged[0] });
        const making = card.getByRole('listitem').filter({ hasText: sale.flagged[1] });

        // One item is ready, the other is only being made.
        await ready.getByRole('button', { name: 'Mulai buat', exact: true }).click();
        await expect(ready).toContainText('Sedang dibuat');
        await ready.getByRole('button', { name: 'Siap', exact: true }).click();
        await expect(ready.getByRole('button', { name: 'Serahkan', exact: true })).toBeVisible(); // a ready item, not just the Siap button
        await making.getByRole('button', { name: 'Mulai buat', exact: true }).click();
        await expect(making).toContainText('Sedang dibuat');
        await expect(card.getByRole('button', { name: 'Serahkan semua', exact: true })).toBeVisible();

        await card.getByRole('button', { name: 'Serahkan semua', exact: true }).click();
        await expect(ready).toHaveCount(0);
        await expect(making).toContainText('Sedang dibuat');
        await expect(making.getByRole('button', { name: 'Siap', exact: true })).toBeVisible();
        // Nothing is ready any more, so there is nothing left to hand over, and another order is untouched.
        await expect(card.getByRole('button', { name: /^Serahkan/ })).toHaveCount(0);
        await expect(page.locator('article', { hasText: other.invoice })).toContainText('Diterima');
    });
});
