import { test, expect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';
import { roleOf, searchCustomers } from '../../support/crm.js';

test.describe('customers: role differences', () => {
    test('a cashier can look customers up but not edit or delete them', async ({ asRole, arrange, uniq }) => {
        const name = uniq('Pelanggan');
        const { customer_id } = arrange('crm.customer', { name });

        const cashier = await asRole(roleOf(roles, 'cashier'));
        await cashier.goto('/dashboard/customers');
        await searchCustomers(cashier, name);
        await expect(cashier.getByRole('link', { name: 'Tambah Pelanggan' }).first()).toBeVisible();
        await expect(cashier.getByRole('link', { name: 'Edit' })).toHaveCount(0);
        await expect(cashier.getByRole('button', { name: 'Hapus' })).toHaveCount(0);

        expect((await cashier.goto(`/dashboard/customers/${customer_id}/edit`)).status()).toBe(403);

        const manager = await asRole(roleOf(roles, 'manager'));
        await manager.goto('/dashboard/customers');
        await searchCustomers(manager, name);
        await expect(manager.getByRole('link', { name: 'Edit' })).toBeVisible();
        await expect(manager.getByRole('button', { name: 'Hapus' })).toBeVisible();
    });
});
