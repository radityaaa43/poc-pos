import { test, expect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';
import { roleOf } from '../../support/crm.js';

// Server refusal for users without the permission and the release backfill (AC-18) are proved in
// tests/Feature/Customers/CustomerCreditTest.php. The pay-later effect of the flag is #240 and #242.
test.describe('customers: may buy on credit', () => {
    test('a manager allows a customer to buy on credit and a cashier cannot', { tag: ['@REQ-003-AC-17'] }, async ({ asRole, arrange, uniq }) => {
        const { customer_id } = arrange('crm.customer', { name: uniq('Pelanggan') });

        const manager = await asRole(roleOf(roles, 'manager'));
        await manager.goto(`/dashboard/customers/${customer_id}`);
        await expect(manager.getByRole('switch', { name: 'Boleh piutang' })).toHaveAttribute('aria-checked', 'false');
        await manager.getByRole('switch').click();
        await manager.getByRole('button', { name: 'Ya', exact: true }).click();
        await expect(manager.getByRole('switch', { name: 'Boleh piutang' })).toHaveAttribute('aria-checked', 'true');

        const cashier = await asRole(roleOf(roles, 'cashier'));
        await cashier.goto(`/dashboard/customers/${customer_id}`);
        await expect(cashier.getByText('Boleh piutang: Ya')).toBeVisible();
        await expect(cashier.getByRole('switch')).toHaveCount(0);
    });

    test('the change is written to the audit log with who, the old and the new value', { tag: ['@REQ-003-AC-25'] }, async ({ asRole, arrange, uniq }) => {
        const name = uniq('Pelanggan');
        const { customer_id } = arrange('crm.customer', { name });

        const manager = await asRole(roleOf(roles, 'manager'));
        await manager.goto(`/dashboard/customers/${customer_id}`);
        await manager.getByRole('switch').click();
        await manager.getByRole('button', { name: 'Ya', exact: true }).click();
        await expect(manager.getByRole('switch', { name: 'Boleh piutang' })).toHaveAttribute('aria-checked', 'true');

        await manager.goto('/dashboard/audit-logs');
        const search = manager.getByPlaceholder('Cari target atau deskripsi');
        await search.fill(name);
        await search.press('Enter');
        const row = manager.getByRole('table').getByRole('row').filter({ hasText: 'customer.credit_updated' });
        await expect(row).toHaveCount(1);
        await expect(row).toContainText('E2E Manager');

        await row.getByRole('link', { name: 'Detail' }).click();
        await expect(manager.getByRole('heading', { name: 'Before' }).locator('xpath=..')).toContainText(/may_buy_on_credit\W*false/);
        await expect(manager.getByRole('heading', { name: 'After' }).locator('xpath=..')).toContainText(/may_buy_on_credit\W*true/);
    });
});
