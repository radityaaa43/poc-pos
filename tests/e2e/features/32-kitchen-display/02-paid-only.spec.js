import { test, expect } from '../../support/fixtures.js';
import { confirmTransfer } from '../../support/kitchen.js';
import { roles } from '../../support/roles.js';

const cashier = roles.find((role) => role.key === 'cashier');

test.describe('32-kitchen-display', () => {
    test.describe.configure({ timeout: 240_000 });

    test('a sale waiting for its transfer stays out of the queue until it is confirmed', { tag: ['@REQ-003-AC-02'] }, async ({ asRole, browser, arrange, uniq }) => {
        const pending = arrange('kitchen-sale', { prefix: uniq('Transfer'), status: 'pending' });
        // A paid sale in the same queue proves the page has loaded the data the pending one is missing from.
        const control = arrange('kitchen-sale', { prefix: uniq('Tunai') });

        const page = await asRole(cashier);
        await page.goto('/dashboard/kitchen');
        await expect(page.getByRole('heading', { name: control.invoice, exact: true })).toBeVisible();
        await expect(page.getByText(pending.flagged[0])).toHaveCount(0);
        await expect(page.getByRole('heading', { name: pending.invoice, exact: true })).toHaveCount(0);

        await confirmTransfer(browser, pending.invoice);

        // The open display picks it up on its next refresh.
        await expect(page.getByRole('heading', { name: pending.invoice, exact: true })).toBeVisible();
        await expect(page.getByText(`1 × ${pending.flagged[0]}`)).toBeVisible();
    });
});
