import { test, expect } from '../../support/fixtures.js';
import { artisan } from '../../support/cli.js';
import { roles } from '../../support/roles.js';

const cashier = roles.find((role) => role.key === 'cashier');

test.describe('32-kitchen-display', () => {
    test('a paid sale the kitchen intake missed reaches the display through the recovery job, once', { tag: ['@REQ-003-AC-02'] }, async ({ asRole, arrange, uniq }) => {
        // Paid, with a line that needs preparation, but no kitchen item: what a failed intake leaves behind.
        const sale = arrange('kitchen.paid-without-intake', { prefix: uniq('Pulih') });
        expect(arrange('kitchen.items', { invoice: sale.invoice }).count).toBe(0);

        const page = await asRole(cashier);
        await page.goto('/dashboard/kitchen');
        await expect(page.getByRole('main').getByRole('heading', { name: 'Layar Dapur', exact: true })).toBeVisible();
        await expect(page.getByRole('heading', { name: sale.invoice, exact: true })).toHaveCount(0);

        expect(artisan(['kitchen:recover-intake'])).toContain('Kitchen intake recovered for');
        artisan(['kitchen:recover-intake']); // the next run finds nothing left to do and adds nothing

        expect(arrange('kitchen.items', { invoice: sale.invoice })).toMatchObject({ count: 1, statuses: ['accepted'] });
        await page.reload();
        await expect(page.getByRole('heading', { name: sale.invoice, exact: true })).toBeVisible();
        await expect(page.getByText(`1 × ${sale.flagged[0]}`)).toBeVisible();
    });
});
