import { test, expect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';
import { field, roleOf } from '../../support/crm.js';

test.describe('customer segments: auto segments', () => {
    test('saving an auto segment refreshes the built-in segments: a big spender joins High Spender', async ({ asRole, arrange, uniq }) => {
        const [customerName, segmentName] = [uniq('Pelanggan Royal'), uniq('Segmen Auto')];
        const { customer_id } = arrange('crm.customer', { name: customerName, member: 1, spent: 2000000, tier: 'silver' });

        const page = await asRole(roleOf(roles, 'manager'));
        await page.goto(`/dashboard/customers/${customer_id}`);
        await expect(page.getByText('Customer belum memiliki segment.')).toBeVisible();

        await page.goto('/dashboard/customer-segments/create');
        await field(page, 'Nama Segment').fill(segmentName);
        await field(page, 'Tipe Segment').selectOption({ label: 'Auto Segment' });
        await field(page, 'Rule Type').selectOption({ label: 'Spending' });
        await field(page, 'Minimum Total Belanja').fill('100000');
        await page.getByRole('button', { name: 'Simpan Segment' }).click();
        await expect(page).toHaveURL(/customer-segments$/);

        // Rp 2.000.000 is above the built-in High Spender threshold (Rp 1.500.000).
        await page.goto(`/dashboard/customers/${customer_id}`);
        const badge = page.locator('span').filter({ hasText: /^High Spender/ }).first();
        await expect(badge).toContainText('auto');
    });

    test('a custom spending segment collects the customers above its threshold', async ({ asRole, arrange, uniq }) => {
        const [customerName, segmentName] = [uniq('Pelanggan Besar'), uniq('Belanja 100rb')];
        arrange('crm.customer', { name: customerName, member: 1, spent: 150000 });

        const page = await asRole(roleOf(roles, 'manager'));
        await page.goto('/dashboard/customer-segments/create');
        await field(page, 'Nama Segment').fill(segmentName);
        await field(page, 'Tipe Segment').selectOption({ label: 'Auto Segment' });
        await field(page, 'Rule Type').selectOption({ label: 'Spending' });
        await field(page, 'Minimum Total Belanja').fill('100000');
        await page.getByRole('button', { name: 'Simpan Segment' }).click();
        await expect(page).toHaveURL(/customer-segments$/);

        await page.getByPlaceholder('Cari nama segment...').fill(segmentName);
        await page.getByRole('link', { name: segmentName }).click();
        await expect(page.getByRole('row').filter({ hasText: customerName })).toContainText('auto');
    });
});
