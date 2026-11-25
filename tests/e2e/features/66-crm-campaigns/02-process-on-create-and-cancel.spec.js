import { test, expect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';
import { field, roleOf } from '../../support/crm.js';

test.describe('CRM campaigns: process on create and cancel', () => {
    test('unchecking draft processes the audience at once; a draft can be cancelled', async ({ asRole, arrange, uniq }) => {
        const [liveName, draftName, segmentName, customerName] = [uniq('Langsung'), uniq('Batal'), uniq('Segmen'), uniq('Citra')];
        const { customer_id } = arrange('crm.customer', { name: customerName, member: 1 });
        arrange('crm.segment', { name: segmentName, customer_ids: String(customer_id) });

        const page = await asRole(roleOf(roles, 'manager'));
        await page.goto('/dashboard/crm-campaigns/create');
        await field(page, 'Nama Campaign').fill(liveName);
        await field(page, 'Customer Type').selectOption({ label: 'Loyalty Member' });
        await page.getByLabel(segmentName).check();
        await page.getByLabel('Simpan sebagai draft').uncheck();
        await page.getByRole('button', { name: 'Simpan Campaign' }).click();

        await expect(page.getByRole('heading', { name: liveName })).toBeVisible();
        await expect(page.getByRole('row').filter({ hasText: customerName })).toBeVisible();
        await expect(page.getByText(/status draft/)).toHaveCount(0);

        // A second campaign is kept as a draft, then cancelled.
        await page.goto('/dashboard/crm-campaigns/create');
        await field(page, 'Nama Campaign').fill(draftName);
        await page.getByRole('button', { name: 'Simpan Campaign' }).click();
        await expect(page.getByText(/status draft/)).toBeVisible();
        await page.getByRole('button', { name: 'Batalkan' }).click();
        await expect(page.getByText(/status cancelled/)).toBeVisible();
        await expect(page.getByRole('button', { name: 'Batalkan' })).toHaveCount(0);
        await expect(page.getByRole('button', { name: 'Proses Audience' })).toHaveCount(0);
    });
});
