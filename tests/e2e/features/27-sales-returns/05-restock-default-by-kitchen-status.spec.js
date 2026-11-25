import { test, expect as baseExpect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';

// Pages are slow when several agents share one machine: wait longer than the 15 s default.
const expect = baseExpect.configure({ timeout: 30_000 });

const manager = roles.find((role) => role.key === 'manager');

test.describe('27-sales-returns: kitchen items', () => {
    test.describe.configure({ timeout: 120_000 });
    test.use({ actionTimeout: 30_000 });

    test('the return form unticks restock for an item being made and keeps it for an accepted one', { tag: ['@REQ-003-AC-11'] }, async ({ asRole, arrange, uniq }) => {
        // One sale with two kitchen lines: the first is still Diterima, the second is already being made.
        const sale = arrange('kitchen-sale', { prefix: uniq('Retur'), flagged: 2, cashier: 'manager@e2e.test' });
        arrange('kitchen.set-item', { invoice: sale.invoice, index: 1, status: 'preparing' });
        const page = await asRole(manager);

        await page.goto(`/dashboard/transactions/history?invoice=${sale.invoice}`);
        await page.getByRole('link', { name: 'Retur', exact: true }).click();
        await expect(page.getByText(`Invoice ${sale.invoice}`)).toBeVisible();

        await page.getByRole('spinbutton').nth(0).fill('1');
        await page.getByRole('spinbutton').nth(1).fill('1');

        // BR-4: on for the accepted item, off for the one being made, with the hint under the second box only.
        const boxes = page.getByRole('checkbox');
        await expect(boxes.nth(0)).toBeChecked();
        await expect(boxes.nth(1)).not.toBeChecked();
        await expect(page.getByText('Sudah dibuat di dapur: default tidak dikembalikan ke stok')).toHaveCount(1);

        // The person's choice wins: tick the box of the item being made.
        await boxes.nth(1).check();
        await expect(boxes.nth(1)).toBeChecked();
    });
});
