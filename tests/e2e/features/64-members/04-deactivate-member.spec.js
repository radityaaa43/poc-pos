import { test, expect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';
import { roleOf } from '../../support/crm.js';

test.describe('members: deactivate', () => {
    test('a manager deactivates a member; the default list hides them, the full list keeps them', async ({ asRole, arrange, uniq }) => {
        const name = uniq('Member Aktif');
        const { customer_id } = arrange('crm.customer', { name, member: 1, region: 1 });
        arrange('crm.regions');

        const page = await asRole(roleOf(roles, 'manager'));
        await page.goto(`/dashboard/members/${customer_id}/edit`);
        await page.getByLabel('Aktif', { exact: true }).uncheck();
        await page.getByRole('button', { name: 'Simpan Perubahan' }).click();
        await expect(page).toHaveURL(new RegExp(`dashboard/members/${customer_id}$`));

        await page.goto('/dashboard/members');
        await page.getByPlaceholder('Cari nama member atau nomor anggota...').fill(name);
        await expect(page.getByRole('row').filter({ hasText: name })).toHaveCount(0);
        await page.locator('select').filter({ has: page.getByRole('option', { name: 'Semua Status' }) }).selectOption({ label: 'Semua Status' });
        await expect(page.getByRole('row').filter({ hasText: name })).toContainText('Nonaktif');
    });
});
