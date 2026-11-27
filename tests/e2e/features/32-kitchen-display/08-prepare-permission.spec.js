import { test, expect } from '../../support/fixtures.js';
import { signIn } from '../../support/platform.js';

test.describe('32-kitchen-display', () => {
    test('a role that may only hand over sees the queue without step buttons, and a direct POST is refused', { tag: ['@REQ-003-AC-10'] }, async ({ page, arrange, uniq }) => {
        // Own user: no shared role has handover without prepare.
        const { email } = arrange('dine.user', { email: `${uniq('antar')}@e2e.test`, permissions: 'dashboard-access,dine-orders-handover' });
        const sale = arrange('kitchen-sale', { prefix: uniq('Izin') });
        await signIn(page, email);
        await page.goto('/dashboard/kitchen');

        const card = page.locator('article', { hasText: sale.invoice });
        await expect(card).toContainText(`1 × ${sale.flagged[0]}`);
        await expect(card).toContainText('Diterima');
        await expect(card.getByRole('button')).toHaveCount(0);

        const status = await page.evaluate(async (id) => {
            const xsrf = decodeURIComponent(document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN=')).split('=')[1]);
            const response = await fetch(`/dashboard/kitchen/orders/${id}/all-ready`, {
                method: 'POST',
                headers: { Accept: 'application/json', 'X-XSRF-TOKEN': xsrf },
            });

            return response.status;
        }, sale.transaction_id);
        expect(status).toBe(403);

        await page.reload();
        await expect(page.locator('article', { hasText: sale.invoice })).toContainText('Diterima');
    });
});
