import { test, expect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';
import { roleOf } from '../../support/crm.js';

test.describe('customers: detail page', () => {
    test('a manager tags a customer with a manual segment and upgrades them to a member', async ({ asRole, arrange, uniq }) => {
        const [name, segmentName] = [uniq('Pelanggan'), uniq('Segmen VIP')];
        const { customer_id } = arrange('crm.customer', { name });
        arrange('crm.segment', { name: segmentName });

        const page = await asRole(roleOf(roles, 'manager'));
        await page.goto(`/dashboard/customers/${customer_id}`);
        await expect(page.getByRole('heading', { name })).toBeVisible();
        await expect(page.getByText('Customer belum memiliki segment.')).toBeVisible();

        await page.getByLabel(new RegExp(segmentName)).check();
        await page.getByRole('button', { name: 'Simpan Segment' }).click();
        await expect(page.getByText('Customer belum memiliki segment.')).toHaveCount(0);
        await expect(page.getByText(segmentName).first()).toBeVisible();

        // Becoming a member issues a member code and shows the loyalty status.
        await page.getByRole('button', { name: 'Jadikan Member' }).click();
        await expect(page.getByRole('button', { name: 'Jadikan Member' })).toHaveCount(0);
        await expect(page.getByText(/Member Code: MEM-/)).toBeVisible();
    });
});
