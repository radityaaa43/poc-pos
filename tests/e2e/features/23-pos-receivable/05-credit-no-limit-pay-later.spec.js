import { test, expect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';
import { roleOf } from '../../support/crm.js';
import { addToCart, chooseCustomer, finish, openPos } from '../../support/pos.js';
import { field } from '../../support/inventory.js';

// The release migration itself is proved in tests/Feature/Customers/CustomerCreditLimitTest.php.
test.describe('POS: credit sales of a customer without a limit', () => {
    test.describe.configure({ timeout: 240_000 });

    test('a credit customer without a limit, overdue included, still buys on pay later', { tag: ['@REQ-007-AC-08'] }, async ({ asRole, arrange, uniq }) => {
        const product = arrange('product', { title: uniq('tanpa-batas'), stock: 5 });
        const { customer_id, name } = arrange('pos.customer', { name: uniq('Langganan') });
        arrange('paylater.setting', { enabled: 1 });
        arrange('paylater.credit', { customer_id });
        arrange('posops.receivable', { customer_id, total: 900000, due_days: -10 });
        arrange('pos.open-shift', { cash: 100000 });

        const page = await asRole(roleOf(roles, 'cashier'));
        await openPos(page);
        await addToCart(page, product.title);
        await chooseCustomer(page, name);
        await expect(page.getByRole('status', { name: 'Batas piutang' }).getByText('Tanpa batas', { exact: true })).toBeVisible();
        await page.getByRole('checkbox').check({ force: true }); // visually hidden behind the styled switch
        await field(page, 'Tanggal Jatuh Tempo').fill(new Date(Date.now() + 14 * 86_400_000).toISOString().slice(0, 10));
        const invoice = await finish(page);

        expect(arrange('paylater.sale-state', { invoice }).receivables).toBe(1);
        expect(arrange('paylater.credit-state', { customer_id })).toMatchObject({ credit_limit: null, receivables: 2 });

        const manager = await asRole(roleOf(roles, 'manager'));
        await manager.goto(`/dashboard/customers/${customer_id}`);
        await expect(manager.getByRole('heading', { name: /^Batas piutang:/ })).toHaveText('Batas piutang: Tanpa batas');
    });
});
