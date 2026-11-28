import { test, expect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';
import { roleOf } from '../../support/crm.js';

// Server refusals and invalid values are proved in tests/Feature/Customers/CustomerCreditLimitTest.php.
test.describe('customers: credit limit', () => {
    test('a manager sets and clears Batas piutang, a cashier only sees it', { tag: ['@REQ-007-AC-01'] }, async ({ asRole, arrange, uniq }) => {
        const { customer_id } = arrange('crm.customer', { name: uniq('Pelanggan') });
        arrange('paylater.credit', { customer_id });
        const state = () => arrange('paylater.credit-state', { customer_id });

        const manager = await asRole(roleOf(roles, 'manager'));
        await manager.goto(`/dashboard/customers/${customer_id}`);
        const heading = (page) => page.getByRole('heading', { name: /^Batas piutang:/ });
        await expect(heading(manager)).toHaveText('Batas piutang: Tanpa batas');

        const field = manager.getByRole('spinbutton', { name: 'Batas piutang' });
        const save = manager.getByRole('button', { name: 'Simpan', exact: true });
        await field.fill('2000000');
        await save.click();
        await expect(heading(manager)).toHaveText(/Batas piutang: Rp\s*2\.000\.000/);
        expect(state().credit_limit).toBe(2000000);

        const cashier = await asRole(roleOf(roles, 'cashier'));
        await cashier.goto(`/dashboard/customers/${customer_id}`);
        await expect(heading(cashier)).toHaveText(/Batas piutang: Rp\s*2\.000\.000/);
        await expect(cashier.getByRole('spinbutton', { name: 'Batas piutang' })).toHaveCount(0);
        await expect(cashier.getByRole('button', { name: 'Simpan', exact: true })).toHaveCount(0);

        await field.fill('');
        await save.click();
        await expect(heading(manager)).toHaveText('Batas piutang: Tanpa batas');
        expect(state().credit_limit).toBeNull();
    });
});
