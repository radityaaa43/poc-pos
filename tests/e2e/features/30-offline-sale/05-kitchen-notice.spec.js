import { test, expect } from '../../support/fixtures.js';
import { fillCart, readQueue } from '../../support/offline-pos.js';
import { cashier } from '../../support/shift-close.js';

test.describe('offline sale: kitchen', () => {
    test('offline, the cashier is told the kitchen has not received the order; the sync puts it on the display', { tag: ['@REQ-003-AC-14'] }, async ({ asRole, arrange, uniq }) => {
        test.setTimeout(120_000);
        const title = uniq('Nasi');
        const customer = uniq('Budi');
        const { product_id } = arrange('product', { title, stock: 10 });
        arrange('kitchen.flag', { product_id });
        arrange('offline.customer', { name: customer });
        arrange('offline.clear-cart');
        arrange('open-shift', { cash: 100000 });
        const page = await asRole(cashier);
        await fillCart(page, { title, customer });
        await page.context().setOffline(true);
        await page.getByRole('button', { name: 'Selesaikan Transaksi' }).click();
        await expect(page.getByText('Dapur belum menerima pesanan ini. Sampaikan langsung ke dapur.').first()).toBeVisible();
        await expect.poll(() => readQueue(page)).toHaveLength(1);
        const [queued] = await readQueue(page);
        expect(arrange('offline.sync-state', { uuid: queued.client_uuid })).toMatchObject({ count: 0 });
        await page.context().setOffline(false);
        await expect(page.getByText('1 transaksi offline tersinkronisasi. Page akan dimuat ulang.')).toBeVisible();
        await expect.poll(() => readQueue(page)).toHaveLength(0);
        const { invoice } = arrange('offline.sync-state', { uuid: queued.client_uuid });
        expect(arrange('kitchen.items', { invoice })).toMatchObject({ count: 1, statuses: ['accepted'] });
        // A second tab: the POS reloads itself after the sync.
        const display = await asRole(cashier);
        await display.goto('/dashboard/kitchen');
        const card = display.locator('article', { hasText: invoice });
        await expect(card).toContainText(`1 × ${title}`);
        await expect(card).toContainText('Diterima');
    });
});
