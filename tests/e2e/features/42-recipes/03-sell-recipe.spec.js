import { test, expect } from '../../support/fixtures.js';
import { role, card, posSearch, posCustomer, posPayExact } from '../../support/inventory.js';

test.describe('42-recipes', () => {
    test('selling 2 portions bills the menu price, uses the ingredients and books the profit', { tag: ['@REQ-001-AC-01', '@REQ-001-AC-06'] }, async ({ asRole, arrange, uniq }) => {
        const prefix = uniq('Jual');
        const customer = uniq('Pelanggan');
        const { recipe } = arrange('inventory.recipe', { prefix, beans: 1000, price: 22000 });
        arrange('inventory.customer', { name: customer });
        arrange('open-shift', { cash: 100000 });
        const pos = await asRole(role('cashier'));

        await pos.goto('/dashboard/transactions');
        await posSearch(pos, prefix);
        await card(pos, recipe).getByRole('button').first().click();
        const line = pos.locator('div.group', { hasText: recipe }).filter({ hasText: '×' });
        await expect(line).toContainText('22.000 × 1');

        // AC-06: the cart follows the quantity.
        await line.getByRole('button', { name: '+', exact: true }).click();
        await expect(line).toContainText('22.000 × 2');
        await expect(line).toContainText('44.000');

        await posCustomer(pos, customer);
        await posPayExact(pos);
        // The sale lands on its invoice: menu price x 2, paid in cash.
        await expect(pos.getByText('INVOICE', { exact: true })).toBeVisible();
        await expect(pos.getByRole('row', { name: new RegExp(recipe) })).toContainText('Rp 44.000');

        // 2 portions use 36 g of beans, 400 ml of water and 2 cups.
        const admin = await asRole(role('super-admin'));
        await admin.goto(`/dashboard/raw-materials?search=${prefix}`);
        await expect(admin.getByRole('row', { name: new RegExp(`${prefix} Biji`) })).toContainText('964');
        await expect(admin.getByRole('row', { name: new RegExp(`${prefix} Air`) })).toContainText('9.600');
        await expect(admin.getByRole('row', { name: new RegExp(`${prefix} Cup`) })).toContainText('98');

        // Profit = Rp 44.000 sales - 2 x (18 x 250 + 200 x 1 + 1 x 1.500) = Rp 31.600.
        await admin.goto('/dashboard/reports/profits');
        await admin.getByRole('button', { name: 'Filter' }).click();
        await admin.getByRole('button', { name: 'Semua pelanggan' }).click();
        await admin.getByPlaceholder('Search...').fill(customer);
        await admin.getByRole('option', { name: customer }).click();
        await admin.getByRole('button', { name: 'Terapkan' }).click();
        const row = admin.getByRole('row').filter({ hasText: customer });
        await expect(row).toContainText('44.000');
        await expect(row).toContainText('31.600');
    });
});
