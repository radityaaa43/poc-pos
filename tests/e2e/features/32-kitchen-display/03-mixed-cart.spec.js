import { test, expect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';

const cashier = roles.find((role) => role.key === 'cashier');

test.describe('32-kitchen-display', () => {
    test('a cart with food and a packaged product shows only the food', { tag: ['@REQ-003-AC-05'] }, async ({ asRole, arrange, uniq }) => {
        const sale = arrange('kitchen-sale', { prefix: uniq('Campur'), flagged: 2, plain: 1 });

        const page = await asRole(cashier);
        await page.goto('/dashboard/kitchen');

        const card = page.locator('article', { hasText: sale.invoice });
        await expect(card.getByRole('heading', { name: sale.invoice, exact: true })).toBeVisible();
        await expect(card.getByRole('listitem')).toHaveCount(2);
        for (const title of sale.flagged) await expect(card).toContainText(`1 × ${title}`);
        await expect(card).not.toContainText(sale.plain[0]);
    });
});
