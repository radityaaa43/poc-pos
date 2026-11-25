import { test, expect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';
import { fillCart, readQueue } from '../../support/offline-pos.js';

const cashier = roles.find((role) => role.key === 'cashier');

// The e2e browser context blocks service workers (fixtures.js), so the PWA asset cache is not
// under test: the POS is loaded online, then the network is cut with context.setOffline(true).
async function prepareOfflineSale({ asRole, arrange, uniq }) {
    const title = uniq('Kopi');
    const customer = uniq('Budi');
    const { product_id } = arrange('product', { title, stock: 10 });
    const { customer_id } = arrange('offline.customer', { name: customer });
    arrange('offline.clear-cart');
    arrange('open-shift', { cash: 100000 });
    const page = await asRole(cashier);
    await fillCart(page, { title, customer });
    await page.context().setOffline(true);
    await expect(page.getByText('Transaksi disimpan offline — akan dikirim saat online kembali')).toBeVisible();

    return { page, product_id, customer_id };
}

test.describe('offline sale: queue', () => {
    test('a cash sale made offline is queued with its client_uuid and nothing reaches the server', async ({ asRole, arrange, uniq }) => {
        test.setTimeout(120_000);
        const { page, product_id, customer_id } = await prepareOfflineSale({ asRole, arrange, uniq });

        await page.getByRole('button', { name: 'Selesaikan Transaksi' }).click();

        await expect.poll(() => readQueue(page)).toHaveLength(1);
        const [queued] = await readQueue(page);
        expect(queued).toMatchObject({
            status: 'pending',
            attempts: 0,
            client_uuid: expect.stringMatching(/^[0-9a-f-]{36}$/),
            data: { customer_id, payment_method: 'cash', grand_total: 10000, cash: 10000, items: [{ product_id, qty: 1 }] },
        });
        // The sale exists only in the browser: no transaction, stock untouched.
        expect(arrange('offline.sync-state', { uuid: queued.client_uuid, product_id })).toMatchObject({
            count: 0, warehouse_stock: 10, product_stock: 10,
        });
    });

    // Bug #109: the queue callback calls an undefined setCarts, so no toast and the cart stays full.
    test.fixme('the cashier is told the sale was saved offline and the cart is emptied', async ({ asRole, arrange, uniq }) => {
        test.setTimeout(120_000);
        const { page } = await prepareOfflineSale({ asRole, arrange, uniq });

        await page.getByRole('button', { name: 'Selesaikan Transaksi' }).click();

        await expect(page.getByText('Transaksi disimpan offline. Akan dikirim saat online.')).toBeVisible();
        await expect(page.getByRole('button', { name: 'Keranjang Kosong' })).toBeVisible();
        await expect.poll(() => readQueue(page)).toHaveLength(1);
    });
});
