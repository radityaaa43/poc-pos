import { test, expect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';
import { roleOf } from '../../support/crm.js';

test.describe('CRM campaigns: delivery logs', () => {
    test('marking every log sent finishes the campaign', async ({ asRole, arrange, uniq }) => {
        const [nameA, nameB] = [uniq('Dewi'), uniq('Eko')];
        const a = arrange('crm.customer', { name: nameA });
        const b = arrange('crm.customer', { name: nameB });
        const { campaign_id } = arrange('crm.campaign', { name: uniq('Manual'), customer_ids: `${a.customer_id},${b.customer_id}` });

        const page = await asRole(roleOf(roles, 'manager'));
        await page.goto(`/dashboard/crm-campaigns/${campaign_id}`);
        await expect(page.getByText(/status ready/)).toBeVisible();
        const rowA = page.getByRole('row').filter({ hasText: nameA });
        const rowB = page.getByRole('row').filter({ hasText: nameB });
        await expect(rowA).toContainText('ready_to_send');
        // The WhatsApp link opens the prepared message.
        await expect(rowA.getByRole('link').nth(1)).toHaveAttribute('href', /https:\/\/wa\.me\//);

        // One log left: the campaign stays "ready".
        await rowA.getByRole('button', { name: 'Tandai terkirim' }).click();
        await expect(rowA).toContainText('sent');
        await expect(rowA).not.toContainText('ready_to_send');
        await expect(page.getByText(/status ready/)).toBeVisible();

        await rowB.getByRole('button', { name: 'Tandai terkirim' }).click();
        await expect(rowB).not.toContainText('ready_to_send');
        await expect(page.getByText(/status processed/)).toBeVisible();
    });

    test('a log can be skipped from the page', async ({ asRole, arrange, uniq }) => {
        const name = uniq('Fani');
        const { customer_id } = arrange('crm.customer', { name });
        const { campaign_id } = arrange('crm.campaign', { name: uniq('Lewati'), customer_ids: String(customer_id) });

        const page = await asRole(roleOf(roles, 'manager'));
        await page.goto(`/dashboard/crm-campaigns/${campaign_id}`);
        const row = page.getByRole('row').filter({ hasText: name });
        await row.getByRole('button', { name: /lewati|skip/i }).click();
        await expect(row).toContainText('skipped');
        await expect(page.getByText(/status processed/)).toBeVisible();
    });
});
