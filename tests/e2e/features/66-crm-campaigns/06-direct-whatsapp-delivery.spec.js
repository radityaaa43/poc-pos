import { test, expect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';
import { field, roleOf } from '../../support/crm.js';

test.describe('CRM campaigns: direct WhatsApp delivery', () => {
    // The e2e app fakes the gateway as connected (E2eServiceProvider, wa.e2e.test).
    test('processing sends every log through the connected gateway and finishes the campaign', async ({ asRole, arrange, uniq }) => {
        const [campaignName, segmentName, customerName] = [uniq('Kirim'), uniq('Segmen'), uniq('Gina')];
        const { customer_id } = arrange('crm.customer', { name: customerName });
        arrange('crm.segment', { name: segmentName, customer_ids: String(customer_id) });

        const page = await asRole(roleOf(roles, 'manager'));
        await page.goto('/dashboard/crm-campaigns/create');
        await field(page, 'Nama Campaign').fill(campaignName);
        await page.getByLabel(segmentName).check();
        await page.getByLabel('Simpan sebagai draft').uncheck();
        await page.getByRole('button', { name: 'Simpan Campaign' }).click();

        await expect(page.getByRole('row').filter({ hasText: customerName })).toContainText('sent');
        await expect(page.getByText(/status processed/)).toBeVisible();
    });
});
