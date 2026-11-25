import { test, expect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';
import { roleOf } from '../../support/crm.js';

test.describe('CRM reminders: queue', () => {
    test('reminder campaigns are listed with their targets and filtered by type and status', async ({ asRole, arrange, uniq }) => {
        const [dueName, promoName, customerName] = [uniq('Tagih'), uniq('Promo'), uniq('Gita')];
        const { customer_id } = arrange('crm.customer', { name: customerName });
        arrange('crm.campaign', { name: dueName, type: 'due_date_reminder', status: 'ready', customer_ids: String(customer_id) });
        arrange('crm.campaign', { name: promoName, type: 'promo_broadcast', status: 'processed' });

        const page = await asRole(roleOf(roles, 'manager'));
        await page.goto('/dashboard/crm-reminders');
        await expect(page.getByRole('heading', { name: 'CRM Reminders' }).first()).toBeVisible();

        const due = page.getByRole('row').filter({ hasText: dueName });
        await expect(due).toContainText('due_date_reminder');
        await expect(due).toContainText('ready');
        await expect(due).toContainText('1 target');
        // The quick action opens the prepared WhatsApp message for the customer.
        await expect(due.getByRole('link', { name: customerName })).toHaveAttribute('href', /https:\/\/wa\.me\//);
        await expect(page.getByRole('row').filter({ hasText: promoName })).toContainText('0 target');

        // Filters narrow the queue.
        const [type, status] = [page.locator('select').nth(0), page.locator('select').nth(1)];
        await type.selectOption({ label: 'Due Date Reminder' });
        await expect(page.getByRole('row').filter({ hasText: dueName })).toBeVisible();
        await expect(page.getByRole('row').filter({ hasText: promoName })).toHaveCount(0);
        await type.selectOption({ label: 'Semua Tipe' });
        // Wait for the unfiltered list before the next filter: the page builds the query from the last response.
        await expect(page.getByRole('row').filter({ hasText: promoName })).toBeVisible();
        await status.selectOption({ label: 'Processed' });
        await expect(page.getByRole('row').filter({ hasText: promoName })).toBeVisible();
        await expect(page.getByRole('row').filter({ hasText: dueName })).toHaveCount(0);
    });

    test('the sidebar page is closed to a cashier', async ({ asRole }) => {
        const cashier = await asRole(roleOf(roles, 'cashier'));
        expect((await cashier.goto('/dashboard/crm-reminders')).status()).toBe(403);
    });
});
