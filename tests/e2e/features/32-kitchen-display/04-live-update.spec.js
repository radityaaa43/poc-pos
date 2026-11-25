import { test, expect } from '../../support/fixtures.js';
import { confirmTransfer } from '../../support/kitchen.js';
import { roles } from '../../support/roles.js';

const cashier = roles.find((role) => role.key === 'cashier');

test.describe('32-kitchen-display', () => {
    test.describe.configure({ timeout: 240_000 });

    test('a sale paid elsewhere appears on the open display without reloading it', { tag: ['@REQ-003-AC-07'] }, async ({ asRole, browser, arrange, uniq }) => {
        const sale = arrange('kitchen-sale', { prefix: uniq('Live'), status: 'pending' });

        const page = await asRole(cashier);
        await page.goto('/dashboard/kitchen');
        await expect(page.getByRole('main').getByRole('heading', { name: 'Layar Dapur', exact: true })).toBeVisible();
        await expect(page.getByRole('heading', { name: sale.invoice, exact: true })).toHaveCount(0);
        // This flag lives in the page's JS state: a reload or a new navigation would wipe it.
        await page.evaluate(() => { window.keptOpen = true; });

        // A manager in another browser context marks the transfer as received.
        await confirmTransfer(browser, sale.invoice);

        await expect(page.getByRole('heading', { name: sale.invoice, exact: true })).toBeVisible();
        await expect(page.getByText(`1 × ${sale.flagged[0]}`)).toBeVisible();
        expect(await page.evaluate(() => window.keptOpen)).toBe(true);
    });
});
