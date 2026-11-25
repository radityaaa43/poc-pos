import { test, expect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';
import { field, roleOf } from '../../support/crm.js';

test.describe('CRM campaigns: create and process', () => {
    test('a draft campaign becomes delivery logs for its segment when processed', async ({ asRole, arrange, uniq }) => {
        const [campaignName, segmentName, nameA, nameB, outsider] = [uniq('Promo Mei'), uniq('Segmen'), uniq('Ani'), uniq('Budi'), uniq('Luar')];
        const a = arrange('crm.customer', { name: nameA });
        const b = arrange('crm.customer', { name: nameB });
        arrange('crm.customer', { name: outsider });
        arrange('crm.segment', { name: segmentName, customer_ids: `${a.customer_id},${b.customer_id}` });

        const page = await asRole(roleOf(roles, 'manager'));
        await page.goto('/dashboard/crm-campaigns');
        await page.getByRole('link', { name: 'Buat Campaign' }).click();

        await field(page, 'Nama Campaign').fill(campaignName);
        await page.getByLabel(segmentName).check();
        await page.getByRole('button', { name: 'Simpan Campaign' }).click();

        // Saved as a draft: no logs yet, a Process button is offered.
        await expect(page.getByRole('heading', { name: campaignName })).toBeVisible();
        await expect(page.getByText(/status draft/)).toBeVisible();
        await expect(page.getByText('Belum ada delivery log untuk campaign ini.')).toBeVisible();

        await page.getByRole('button', { name: 'Proses Audience' }).click();

        // One log per member of the segment, and nobody else, each with its message for that customer.
        const rowA = page.getByRole('row').filter({ hasText: nameA });
        await expect(rowA).toContainText(`Halo ${nameA}`);
        await expect(page.getByRole('row').filter({ hasText: nameB })).toContainText(`Halo ${nameB}`);
        await expect(page.getByRole('row').filter({ hasText: outsider })).toHaveCount(0);
        await expect(page.getByText(/status draft/)).toHaveCount(0);
        await expect(page.getByRole('button', { name: 'Proses Audience' })).toHaveCount(0);

        // The list shows the campaign with its two logs.
        await page.goto('/dashboard/crm-campaigns');
        const row = page.getByRole('row').filter({ hasText: campaignName });
        await expect(row).toContainText('promo_broadcast');
        await expect(row.getByRole('cell').nth(3)).toHaveText('2');
    });
});
