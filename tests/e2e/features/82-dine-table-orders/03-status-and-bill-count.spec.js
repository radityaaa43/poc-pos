import { test, expect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';
import { openTables, sendToTable, tableButton } from '../../support/table-order.js';

const role = (key) => roles.find((item) => item.key === key);

// REQ-009 J2: the table list shows the computed status and the number of unpaid bills; paying the last order (nothing
// to cook, so it is already served) frees the table with nothing typed. Dipesan needs #443's reservations.
test.describe('82-dine-table-orders', () => {
    test('a table is Terisi with its unpaid bills and Kosong again once the last order finishes', { tag: ['@REQ-009-AC-04', '@REQ-009-AC-05'] }, async ({ asRole, arrange, uniq }) => {
        test.setTimeout(180_000);
        const { table_name } = arrange('dine.table', { area: uniq('Area'), name: uniq('M') });
        const product = arrange('product', { title: uniq('Kerupuk'), stock: 10 });
        arrange('paylater.setting', { enabled: 1 });
        arrange('pos.open-shift', { cash: 100000 });
        const page = await asRole(role('cashier'));

        await sendToTable(page, { product: product.title, table: table_name, choose: ['Bayar Belakangan'] });
        let dialog = await openTables(page);
        await expect(tableButton(dialog, table_name)).toContainText('Terisi');
        await expect(tableButton(dialog, table_name)).toContainText('1 tagihan Belum Lunas');
        await dialog.getByRole('button', { name: 'Batal' }).click();

        const manager = await asRole(role('manager'));
        await manager.goto('/dashboard/dine-tables');
        await manager.getByRole('button', { name: 'Daftar' }).click();
        const row = manager.getByRole('row').filter({ hasText: table_name });
        await expect(row).toContainText('Terisi');
        await expect(row).toContainText('1 tagihan Belum Lunas');

        await page.getByRole('button', { name: /Belum Lunas/ }).click();
        const bill = page.getByTestId('open-bills').getByRole('listitem').filter({ hasText: table_name });
        await bill.getByRole('button', { name: 'Bayar' }).click();
        const payment = page.getByRole('dialog');
        await payment.getByLabel('Uang diterima').fill('10000');
        await payment.getByRole('button', { name: 'Bayar', exact: true }).click();
        await page.waitForURL(/\/transactions\/TRX-[A-Z0-9]+\/print/);

        await page.goto('/dashboard/transactions');
        dialog = await openTables(page);
        await expect(tableButton(dialog, table_name)).toContainText('Kosong');
        await expect(tableButton(dialog, table_name)).toContainText('0 tagihan Belum Lunas');
    });
});
