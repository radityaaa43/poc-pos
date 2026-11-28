import { test, expect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';
import { roleOf } from '../../support/crm.js';
import { chooseCustomer, openPos } from '../../support/pos.js';

test.describe('POS: the customer credit panel', () => {
    test.describe.configure({ timeout: 240_000 });

    test('shows Batas, Terpakai and Sisa, or Tanpa batas, and warns about an overdue receivable', { tag: ['@REQ-007-AC-03'] }, async ({ asRole, arrange, uniq }) => {
        // A credit customer owing `owed`, optionally with a limit and a due date in the past.
        const customerOwing = (owed, { limit, dueDays = 14 } = {}) => {
            const { customer_id, name } = arrange('pos.customer', { name: uniq('Kredit') });
            arrange('paylater.credit', { customer_id, ...(limit === undefined ? {} : { limit }) });
            arrange('posops.receivable', { customer_id, total: owed, due_days: dueDays });

            return name;
        };
        const within = customerOwing(300000, { limit: 500000 });
        const above = customerOwing(300000, { limit: 100000 });
        const unlimited = customerOwing(150000, { dueDays: -3 });
        arrange('pos.open-shift', { cash: 100000 });

        const page = await asRole(roleOf(roles, 'cashier'));
        const panel = page.getByRole('status', { name: 'Batas piutang' });
        const figure = (term) => panel.getByText(term, { exact: true }).locator('xpath=following-sibling::dd');
        // A reload clears the picked customer.
        const pick = async (name) => {
            await openPos(page);
            await chooseCustomer(page, name);
        };

        await pick(within);
        await expect(figure('Batas')).toHaveText(/Rp\s*500\.000/);
        await expect(figure('Terpakai')).toHaveText(/Rp\s*300\.000/);
        await expect(figure('Sisa')).toHaveText(/Rp\s*200\.000/);
        await expect(panel.getByText('Ada piutang lewat jatuh tempo')).toHaveCount(0);

        await pick(above);
        await expect(figure('Sisa')).toHaveText(/Rp\s*0$/);

        await pick(unlimited);
        await expect(panel.getByText('Tanpa batas', { exact: true })).toBeVisible();
        await expect(figure('Terpakai')).toHaveText(/Rp\s*150\.000/);
        await expect(panel.getByText('Sisa', { exact: true })).toHaveCount(0);
        await expect(panel.getByText('Ada piutang lewat jatuh tempo')).toBeVisible();
    });
});
