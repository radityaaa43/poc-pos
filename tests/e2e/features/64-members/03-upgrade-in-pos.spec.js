import { test, expect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';
import { openPos, pickCustomer, roleOf } from '../../support/crm.js';

test.describe('members: upgrade at the till', () => {
    test('a plain customer chosen in the POS can be upgraded to a member on the spot', async ({ asRole, arrange, uniq }) => {
        const name = uniq('Calon Member');
        arrange('crm.pos-ready');
        const { customer_id } = arrange('crm.customer', { name });

        const cashier = await asRole(roleOf(roles, 'cashier'));
        await openPos(cashier);
        await pickCustomer(cashier, name);
        await expect(cashier.getByText('Non-member')).toBeVisible();

        await cashier.getByRole('button', { name: 'Upgrade member' }).click();
        await expect(cashier.getByText('Loyalty Member')).toBeVisible();
        await expect(cashier.getByRole('button', { name: 'Upgrade member' })).toHaveCount(0);

        const manager = await asRole(roleOf(roles, 'manager'));
        await manager.goto(`/dashboard/members/${customer_id}`);
        await expect(manager.getByRole('heading', { name })).toBeVisible();
        await expect(manager.getByText(/MEM-/).first()).toBeVisible();
    });
});
