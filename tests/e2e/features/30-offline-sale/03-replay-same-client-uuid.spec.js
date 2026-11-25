import { test, expect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';
import { PASSWORD } from '../../support/roles.js';
import { fillCart, readQueue } from '../../support/offline-pos.js';

const cashier = roles.find((role) => role.key === 'cashier');

test.describe('offline sale: idempotent replay', () => {
    // The browser flush cannot authenticate yet (bug #107), so the queued payload is replayed
    // against the same sync endpoint with a bearer token, which is what the server contract covers.
    test('replaying a queued sale with the same client_uuid creates no second transaction', async ({ asRole, arrange, uniq, request }) => {
        test.setTimeout(180_000);
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
        const [{ data, client_uuid, id }] = await readQueue(page);
        const body = { transactions: [{ ...data, queue_id: id }] };

        const login = await request.post('/api/v1/auth/login', { data: { email: cashier.email, password: PASSWORD } });
        const headers = { Authorization: `Bearer ${(await login.json()).data.token}`, Accept: 'application/json' };
        const sync = async (payload) => (await (await request.post('/api/v1/pos/transactions/sync', { headers, data: payload })).json()).data.results[0];

        const first = await sync(body);
        expect(first).toMatchObject({ client_uuid, status: 'synced' });
        expect(arrange('offline.sync-state', { uuid: client_uuid, product_id })).toMatchObject({ count: 1, warehouse_stock: 9, product_stock: 9 });

        const replay = await sync(body);
        expect(replay).toMatchObject({ client_uuid, status: 'duplicate', transaction_id: first.transaction_id, invoice: first.invoice });

        // The same uuid with a different basket is refused, not applied.
        const changed = await sync({ transactions: [{ ...body.transactions[0], items: [{ ...data.items[0], qty: 2 }] }] });
        expect(changed).toMatchObject({ client_uuid, status: 'conflict' });

        expect(arrange('offline.sync-state', { uuid: client_uuid, product_id })).toMatchObject({
            count: 1, invoice: first.invoice, warehouse_stock: 9, product_stock: 9,
        });
    });
});
