import { test, expect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';

const role = (key) => roles.find((item) => item.key === key);

test.describe('82-dine-table-orders', () => {
    test('the table order dialog and the table status read in English too', { tag: ['@REQ-009-AC-22'] }, async ({ asRole, arrange, uniq }) => {
        test.setTimeout(180_000);
        const { table_name } = arrange('dine.table', { area: uniq('Area'), name: uniq('M') });
        arrange('pos.open-shift', { cash: 100000 });
        const page = await asRole(role('cashier'));
        // Only the browser's language: changing the shared user's saved language would affect other steps.
        await page.addInitScript(() => localStorage.setItem('i18nextLng', 'en'));

        await page.goto('/dashboard/transactions');
        await page.getByRole('button', { name: 'Table order', exact: true }).click();
        const dialog = page.getByRole('dialog');
        const table = dialog.getByRole('button', { name: new RegExp(table_name) });
        await expect(table).toContainText('Free');
        await expect(table).toContainText('Unpaid bills: 0');
        await table.click();
        await expect(dialog.getByRole('radio', { name: 'Pay at the counter' })).toBeChecked();
        await expect(dialog.getByText('The cart is empty. Add items first.')).toBeVisible();
        await expect(dialog.getByRole('button', { name: 'Send order' })).toBeDisabled();

        const manager = await asRole(role('manager'));
        await manager.addInitScript(() => localStorage.setItem('i18nextLng', 'en'));
        await manager.goto('/dashboard/dine-tables');
        await manager.getByRole('button', { name: 'Daftar' }).click();
        await expect(manager.getByRole('row').filter({ hasText: table_name })).toContainText('Free');
    });
});
