import { test, expect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';
import { readQueue } from '../../support/offline-pos.js';
import { addToCart, openPos } from '../../support/pos.js';
import { openTables, tableButton } from '../../support/table-order.js';

const cashier = roles.find((role) => role.key === 'cashier');
const refusal = 'Pesanan meja memerlukan koneksi internet.';

// REQ-009: a table bill lives on the server, so offline the POS refuses table orders and queues nothing.
test.describe('82-dine-table-orders', () => {
    test('offline, a table order is refused with a message and nothing is queued', { tag: ['@REQ-009-AC-21'] }, async ({ asRole, arrange, uniq }) => {
        test.setTimeout(180_000);
        const { table_name } = arrange('dine.table', { area: uniq('Area'), name: uniq('M') });
        const product = arrange('product', { title: uniq('Kopi'), stock: 10 });
        arrange('pos.open-shift', { cash: 100000 });
        const page = await asRole(cashier);
        await openPos(page);
        await addToCart(page, product.title);

        // The network drops while the dialog is open: sending is refused.
        const dialog = await openTables(page);
        await tableButton(dialog, table_name).click();
        await page.context().setOffline(true);
        await dialog.getByRole('button', { name: 'Kirim pesanan' }).click();
        await expect(page.getByText(refusal).first()).toBeVisible();
        await dialog.getByRole('button', { name: 'Batal' }).click();
        await expect(dialog).toBeHidden();

        // Already offline: the dialog does not open.
        await page.getByRole('button', { name: 'Pesanan meja', exact: true }).click();
        await expect(page.getByText(refusal).first()).toBeVisible();
        await expect(page.getByRole('dialog')).toHaveCount(0);

        expect(await readQueue(page)).toEqual([]);
        await page.context().setOffline(false);
        expect(arrange('dine.table-orders', { name: table_name }).orders).toEqual([]);
    });
});
