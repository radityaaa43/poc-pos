import { test, expect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';
import { fillCart, readQueue } from '../../support/offline-pos.js';

const cashier = roles.find((role) => role.key === 'cashier');

test.describe('offline sale: shift close', () => {
    test('a sale still queued on this device disables the shift close', { tag: ['@REQ-003-AC-12'] }, async ({ asRole, arrange, uniq }) => {
        test.setTimeout(120_000);
        const title = uniq('Kopi');
        const customer = uniq('Budi');
        arrange('product', { title, stock: 10 });
        arrange('offline.customer', { name: customer });
        arrange('offline.clear-cart');
        const { shift_id } = arrange('open-shift', { cash: 100000 });
        const page = await asRole(cashier);

        await fillCart(page, { title, customer });
        await page.context().setOffline(true);
        await page.getByRole('button', { name: 'Selesaikan Transaksi' }).click();
        await expect.poll(() => readQueue(page)).toHaveLength(1);

        // Back online, but the sync keeps failing, so the sale stays queued.
        await page.route(/transactions\/sync/, (route) => route.abort());
        await page.context().setOffline(false);
        await page.goto(`/dashboard/cashier-shifts/${shift_id}`);

        await expect(page.getByRole('button', { name: 'Sinkronkan transaksi offline dulu' })).toBeDisabled();
        expect(await readQueue(page)).toHaveLength(1);
    });
});
