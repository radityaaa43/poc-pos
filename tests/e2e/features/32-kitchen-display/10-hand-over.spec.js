import { test, expect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';

const cashier = roles.find((role) => role.key === 'cashier');

test.describe('32-kitchen-display', () => {
    test('Serahkan hands over a ready item: it leaves the queue and the paid dine-in order completes', { tag: ['@REQ-003-AC-09'] }, async ({ asRole, page: guest, arrange, uniq }) => {
        // A guest order accepted and paid in cash: the paid bill reaches the kitchen as one item.
        const sale = arrange('kitchen-sale', { prefix: uniq('Serah'), dine: 1 });

        const page = await asRole(cashier);
        await page.goto('/dashboard/kitchen');
        const card = page.locator('article', { hasText: sale.table_name });
        const item = card.getByRole('listitem').filter({ hasText: sale.flagged[0] });
        await expect(item).toContainText('Diterima');
        // Nothing to hand over before the item is ready.
        await expect(card.getByRole('button', { name: /^Serahkan/ })).toHaveCount(0);

        await item.getByRole('button', { name: 'Mulai buat', exact: true }).click();
        await expect(item).toContainText('Sedang dibuat');
        await expect(card.getByRole('button', { name: /^Serahkan/ })).toHaveCount(0);
        await item.getByRole('button', { name: 'Siap', exact: true }).click();
        await expect(item).toContainText('Siap');
        await expect(item.getByRole('button', { name: 'Serahkan', exact: true })).toBeVisible();
        await guest.goto(`/dine-order/${sale.access_token}`);
        await expect(guest.getByTestId('dine-status')).toHaveText('Siap');

        // Served and already paid: the item leaves the queue and the order completes.
        await item.getByRole('button', { name: 'Serahkan', exact: true }).click();
        await expect(card).toHaveCount(0);
        expect(arrange('dine.order-state', { access_token: sale.access_token })).toMatchObject({ status: 'completed', served: true, payment_status: 'paid' });
        await guest.reload();
        await expect(guest.getByTestId('dine-status')).toHaveText('Selesai');
    });
});
