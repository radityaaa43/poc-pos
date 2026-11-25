import { test, expect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';
import { fillCart, readQueue } from '../../support/offline-pos.js';

const cashier = roles.find((role) => role.key === 'cashier');

test.describe('offline sale: flush', () => {
    test('going online flushes the queued sale exactly once', async ({ asRole, arrange, uniq }) => {
        test.setTimeout(120_000);
        const title = uniq('Kopi');
        const customer = uniq('Budi');
        const { product_id } = arrange('product', { title, stock: 10 });
        arrange('offline.customer', { name: customer });
        arrange('offline.clear-cart');
        arrange('open-shift', { cash: 100000 });
        const page = await asRole(cashier);

        await fillCart(page, { title, customer });
        await page.context().setOffline(true);
        await page.getByRole('button', { name: 'Selesaikan Transaksi' }).click();
        await expect.poll(() => readQueue(page)).toHaveLength(1);
        const [queued] = await readQueue(page);

        await page.context().setOffline(false);
        await expect(page.getByText('1 transaksi offline tersinkronisasi. Page akan dimuat ulang.')).toBeVisible();
        await expect.poll(() => readQueue(page)).toHaveLength(0);

        const state = arrange('offline.sync-state', { uuid: queued.client_uuid, product_id });
        expect(state).toMatchObject({ count: 1, warehouse_stock: 9, product_stock: 9 });
    });
});
