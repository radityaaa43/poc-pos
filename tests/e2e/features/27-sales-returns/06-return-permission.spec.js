import { test, expect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';

const cashier = roles.find((role) => role.key === 'cashier');

test.describe('27-sales-returns: kitchen items', () => {
    test('a user without the return permission has no way to remove a kitchen item', { tag: ['@REQ-003-AC-22'] }, async ({ asRole, arrange, uniq }) => {
        const sale = arrange('kitchen-sale', { prefix: uniq('Tanpa retur'), flagged: 1 });
        const page = await asRole(cashier);

        // No return button on the sale and no sales returns page...
        await page.goto(`/dashboard/transactions/history?invoice=${sale.invoice}`);
        await expect(page.getByText(sale.invoice).first()).toBeVisible();
        await expect(page.getByRole('link', { name: 'Retur', exact: true })).toHaveCount(0);
        expect((await page.goto('/dashboard/sales-returns')).status()).toBe(403);

        // ...and the kitchen display offers no remove or cancel action on the item: only the steps of making it.
        await page.goto('/dashboard/kitchen');
        const card = page.locator('article', { hasText: sale.invoice });
        await expect(card.getByRole('button', { name: 'Mulai buat', exact: true })).toBeVisible();
        await expect(card.getByRole('button', { name: /hapus|batal|cancel/i })).toHaveCount(0);
    });
});
