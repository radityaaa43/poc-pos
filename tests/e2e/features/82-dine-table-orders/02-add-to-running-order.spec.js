import { test, expect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';
import { sendToTable } from '../../support/table-order.js';

const cashier = roles.find((role) => role.key === 'cashier');

// REQ-009 J1.3 (REQ-003 AC-21): items added from the POS to a running table order are their own sale, linked to it.
test.describe('82-dine-table-orders', () => {
    test('adding to a running table order links the new sale to it', { tag: ['@REQ-009-AC-03'] }, async ({ asRole, arrange, uniq }) => {
        test.setTimeout(180_000);
        const { table_name } = arrange('dine.table', { area: uniq('Area'), name: uniq('M') });
        const product = arrange('product', { title: uniq('Teh'), stock: 10 });
        arrange('pos.open-shift', { cash: 100000 });
        const page = await asRole(cashier);

        await sendToTable(page, { product: product.title, table: table_name });
        const [original] = arrange('dine.table-orders', { name: table_name }).orders;

        await sendToTable(page, { product: product.title, table: table_name, choose: [`Tambah ke pesanan #${original.order_id} (1 item)`] });

        const [, addOn] = arrange('dine.table-orders', { name: table_name }).orders;
        expect(addOn).toMatchObject({ source: 'pos', add_on_to: original.order_id, parent_transaction_id: original.transaction_id });
    });
});
