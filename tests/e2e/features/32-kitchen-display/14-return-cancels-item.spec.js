import { test, expect as baseExpect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';

// Pages are slow when several agents share one machine: wait longer than the 15 s default.
const expect = baseExpect.configure({ timeout: 30_000 });

const cashier = roles.find((role) => role.key === 'cashier');
const manager = roles.find((role) => role.key === 'manager');

test.describe('32-kitchen-display', () => {
    test.describe.configure({ timeout: 180_000 });
    test.use({ actionTimeout: 30_000 });

    test('a sales return lowers the quantity on the display, then cancels the item', { tag: ['@REQ-003-AC-11'] }, async ({ asRole, arrange, uniq }) => {
        // A sale sold by the manager (the return needs the manager's open shift) with one line of 3, still Diterima.
        const sale = arrange('kitchen-sale', { prefix: uniq('Retur dapur'), flagged: 1, cashier: 'manager@e2e.test' });
        arrange('kitchen.set-item', { invoice: sale.invoice, status: 'accepted', qty: 3 });
        const managerPage = await asRole(manager);
        const kitchenPage = await asRole(cashier);

        const giveBack = async (qty) => {
            await managerPage.goto(`/dashboard/transactions/history?invoice=${sale.invoice}`);
            await managerPage.getByRole('link', { name: 'Retur', exact: true }).click();
            await managerPage.getByRole('spinbutton').fill(String(qty));
            await managerPage.getByPlaceholder('Alasan retur').fill('Salah pesan');
            await managerPage.getByRole('button', { name: 'Buat Draft' }).click();
            await managerPage.getByRole('button', { name: 'Selesaikan Retur' }).click();
            await expect(managerPage.getByText('Completed', { exact: true })).toBeVisible();
        };
        const item = () => kitchenPage.locator('article', { hasText: sale.invoice }).getByRole('listitem');

        await kitchenPage.goto('/dashboard/kitchen');
        await expect(item()).toContainText('3 ×');

        // BR-3: returning 1 of 3 lowers the quantity and leaves the item in the queue.
        await giveBack(1);
        await kitchenPage.goto('/dashboard/kitchen');
        await expect(item()).toContainText('2 ×');
        await expect(item()).toContainText('Diterima');

        // BR-2, BR-9: returning the rest (completed) shows Dibatalkan, with no buttons left on the item.
        await giveBack(2);
        await kitchenPage.goto('/dashboard/kitchen');
        await expect(item()).toContainText('Dibatalkan');
        await expect(item().getByRole('button')).toHaveCount(0);
    });
});
