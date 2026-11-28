import { test, expect } from '../../support/fixtures.js';
import { queueRefusedSale, readQueue } from '../../support/offline-pos.js';
import { cashier } from '../../support/shift-close.js';

test.describe('offline sale: retry now', () => {
    test('after the stock is received, retry now syncs the sale and it leaves the list', async ({ asRole, arrange, uniq }) => {
        test.setTimeout(120_000);
        const { page, product_id, queued } = await queueRefusedSale({ asRole, arrange, uniq, role: cashier });
        arrange('pos.set-stock', { product_id, stock: 10 });
        await page.getByRole('button', { name: 'Transaksi offline tertunda (1)' }).click();
        const dialog = page.getByRole('dialog');

        await dialog.getByRole('button', { name: 'Coba sinkron sekarang' }).click();

        await expect(dialog.getByText('Tidak ada transaksi offline yang tertunda.')).toBeVisible();
        expect(await readQueue(page)).toHaveLength(0);
        expect(arrange('offline.sync-state', { uuid: queued.client_uuid, product_id })).toMatchObject({
            count: 1, warehouse_stock: 9, product_stock: 9,
        });
        await dialog.getByRole('button', { name: 'Tutup' }).click();
        await expect(page.getByRole('button', { name: /Transaksi offline tertunda/ })).toHaveCount(0);
    });
});
