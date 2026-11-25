import { test, expect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';

const manager = roles.find((role) => role.key === 'manager');

test.describe('38-dine-orders-queue', () => {
    test('reject a guest order with a reason: it leaves the queue, stock is untouched, the guest sees it rejected', async ({ asRole, page: guest, arrange, uniq }) => {
        const { table_name, token } = arrange('dine.table', { area: uniq('Area'), name: uniq('M') });
        const { product_id } = arrange('product', { title: uniq('Bakso'), stock: 5 });
        const { access_token } = arrange('dine.order', { token, product_id, qty: 2, notes: 'pedas' });

        await guest.goto(`/dine-order/${access_token}`);
        await expect(guest.getByText('Menunggu Konfirmasi', { exact: true })).toBeVisible();

        const page = await asRole(manager);
        await page.goto('/dashboard/dine-orders');
        const card = page.locator('div.rounded-2xl', { hasText: table_name });
        page.once('dialog', (prompt) => prompt.accept('Dapur tutup'));
        await card.getByRole('button', { name: 'Tolak' }).click();

        // Only submitted and accepted orders are listed.
        await expect(page.locator('div.rounded-2xl', { hasText: table_name })).toHaveCount(0);

        // The reason is appended to the order's notes; no transaction, no stock movement.
        expect(arrange('dine.order-state', { access_token, product_id })).toMatchObject({
            status: 'rejected', notes: 'pedas\n[Penolakan: Dapur tutup]', invoice: null, product_stock: 5, warehouse_stock: 5,
        });

        await expect(guest.getByText('Ditolak', { exact: true })).toBeVisible({ timeout: 30_000 });
    });

    test('cancelling the reason prompt rejects nothing', async ({ asRole, arrange, uniq }) => {
        const { table_name, token } = arrange('dine.table', { area: uniq('Area'), name: uniq('M') });
        const { product_id } = arrange('product', { title: uniq('Mie'), stock: 5 });
        const { access_token } = arrange('dine.order', { token, product_id, qty: 1 });

        const page = await asRole(manager);
        await page.goto('/dashboard/dine-orders');
        const card = page.locator('div.rounded-2xl', { hasText: table_name });
        page.once('dialog', (prompt) => prompt.dismiss());
        await card.getByRole('button', { name: 'Tolak' }).click();

        await expect(card).toContainText('Menunggu');
        expect(arrange('dine.order-state', { access_token })).toMatchObject({ status: 'submitted' });
    });
});
