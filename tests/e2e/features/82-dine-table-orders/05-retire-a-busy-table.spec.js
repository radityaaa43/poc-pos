import { test, expect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';
import { sendToTable } from '../../support/table-order.js';

const role = (key) => roles.find((item) => item.key === key);

// REQ-009 decision 19: a table with a running order stays, and stays on; the refusal names the order.
test.describe('82-dine-table-orders', () => {
    test('a Terisi table can be neither switched off nor deleted', { tag: ['@REQ-009-AC-23'] }, async ({ asRole, arrange, uniq }) => {
        test.setTimeout(180_000);
        const { table_name } = arrange('dine.table', { area: uniq('Area'), name: uniq('M') });
        const product = arrange('product', { title: uniq('Soto'), stock: 10 });
        arrange('pos.open-shift', { cash: 100000 });
        await sendToTable(await asRole(role('cashier')), { product: product.title, table: table_name });
        const [order] = arrange('dine.table-orders', { name: table_name }).orders;
        const refusal = new RegExp(`#${order.order_id}\\b`);

        const manager = await asRole(role('manager'));
        await manager.goto('/dashboard/dine-tables');
        await manager.getByRole('button', { name: 'Daftar' }).click();

        manager.once('dialog', (confirm) => confirm.accept());
        await manager.getByRole('button', { name: `Hapus ${table_name}` }).click();
        await expect(manager.getByRole('status').filter({ hasText: refusal })).toBeVisible();
        expect(arrange('dine.table-state', { name: table_name }).exists).toBe(true);

        await manager.reload();
        await manager.getByRole('button', { name: 'Daftar' }).click();
        await manager.getByRole('button', { name: `Edit ${table_name}` }).click();
        const dialog = manager.getByRole('dialog');
        await dialog.getByLabel('Meja aktif').uncheck();
        await dialog.getByRole('button', { name: 'Perbarui' }).click();
        await expect(manager.getByRole('status').filter({ hasText: refusal })).toBeVisible();
        expect(arrange('dine.table-state', { name: table_name })).toMatchObject({ exists: true, is_active: true });
    });
});
