import { test, expect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';
import { addToCart, openPos, rupiah } from '../../support/pos.js';
import { openTables, tableButton } from '../../support/table-order.js';

const cashier = roles.find((role) => role.key === 'cashier');

// REQ-009 J1.1, J1.2: the cashier picks a table and sends the cart; it is an unpaid bill of that table and, as pay
// later, goes to the kitchen at once.
test.describe('83-dine-table-orders', () => {
    test('a table order picked on the POS joins the table bill and reaches the kitchen', { tag: ['@REQ-009-AC-01', '@REQ-009-AC-02'] }, async ({ asRole, arrange, uniq }) => {
        test.setTimeout(180_000);
        const { table_name } = arrange('dine.table', { area: uniq('Area'), name: uniq('M') });
        const { table_name: inactive } = arrange('dine.table', { area: uniq('Area'), name: uniq('Off'), active: 0 });
        const product = arrange('product', { title: uniq('Mie'), stock: 10 });
        arrange('kitchen.flag', { product_id: product.product_id });
        arrange('paylater.setting', { enabled: 1 });
        arrange('pos.open-shift', { cash: 100000 });
        const page = await asRole(cashier);

        await openPos(page);
        await addToCart(page, product.title);
        const dialog = await openTables(page);
        await expect(tableButton(dialog, inactive)).toHaveCount(0);
        await expect(tableButton(dialog, table_name)).toContainText('Kosong');

        await tableButton(dialog, table_name).click();
        await expect(dialog.getByText(rupiah(10000))).toBeVisible();
        await dialog.getByRole('radio', { name: 'Bayar Belakangan' }).check();
        await dialog.getByRole('button', { name: 'Kirim pesanan' }).click();

        await expect(page.getByText(`Pesanan terkirim ke ${table_name}`)).toBeVisible();
        await expect(page.getByText('Keranjang kosong', { exact: true })).toBeVisible();
        await page.getByRole('button', { name: /Belum Lunas/ }).click();
        await expect(page.getByTestId('open-bills').getByRole('listitem').filter({ hasText: table_name })).toContainText(rupiah(10000));

        const [order] = arrange('dine.table-orders', { name: table_name }).orders;
        expect(order.source).toBe('pos');
        await expect.poll(() => arrange('dine.order-state', { access_token: order.access_token, product_id: product.product_id })).toMatchObject({
            status: 'accepted', payment_option: 'pay_later', payment_status: 'unpaid', grand_total: 10000, kitchen_items: 1, product_stock: 9, warehouse_stock: 9,
        });
    });
});
