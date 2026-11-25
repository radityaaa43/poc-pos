import { test, expect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';
import { roleOf } from '../../support/crm.js';

test.describe('price lists: roles', () => {
    test('a cashier cannot open the price list settings', async ({ asRole }) => {
        const cashier = await asRole(roleOf(roles, 'cashier'));
        const index = await cashier.goto('/dashboard/settings/price-lists');
        expect(index.status()).toBe(403);
    });

    test('a cashier cannot read a price list with its prices', async ({ asRole, arrange, uniq }) => {
        const { price_list_id } = arrange('crm.price-list', { name: uniq('Rahasia') });

        const cashier = await asRole(roleOf(roles, 'cashier'));
        const detail = await cashier.goto(`/dashboard/settings/price-lists/${price_list_id}`);
        expect(detail.status()).toBe(403);
    });
});
