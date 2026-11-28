import { test, expect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';
import { roleOf } from '../../support/crm.js';

test.describe('customers: credit limit audit', () => {
    test('a limit change is audited with who, when, the old and the new value', { tag: ['@REQ-007-AC-02'] }, async ({ asRole, arrange, uniq }) => {
        const name = uniq('Pelanggan');
        const { customer_id } = arrange('crm.customer', { name });
        arrange('paylater.credit', { customer_id, limit: 1000000 });

        const manager = await asRole(roleOf(roles, 'manager'));
        await manager.goto(`/dashboard/customers/${customer_id}`);
        await manager.getByRole('spinbutton', { name: 'Batas piutang' }).fill('2000000');
        await manager.getByRole('button', { name: 'Simpan', exact: true }).click();
        await expect(manager.getByRole('heading', { name: /^Batas piutang:/ })).toHaveText(/Rp\s*2\.000\.000/);

        const { audits } = arrange('paylater.credit-state', { customer_id });
        expect(audits).toHaveLength(1);
        expect(audits[0]).toMatchObject({ user: roleOf(roles, 'manager').email, before: 1000000, after: 2000000 });
        expect(Date.parse(audits[0].at)).not.toBeNaN();

        await manager.goto('/dashboard/audit-logs');
        const search = manager.getByPlaceholder('Cari target atau deskripsi');
        await search.fill(name);
        await search.press('Enter');
        const row = manager.getByRole('table').getByRole('row').filter({ hasText: 'customer.credit_limit_updated' });
        await expect(row).toHaveCount(1);
        await expect(row).toContainText('E2E Manager');

        await row.getByRole('link', { name: 'Detail' }).click();
        await expect(manager.getByRole('heading', { name: 'Before' }).locator('xpath=..')).toContainText(/credit_limit\W*1000000/);
        await expect(manager.getByRole('heading', { name: 'After' }).locator('xpath=..')).toContainText(/credit_limit\W*2000000/);
    });
});
