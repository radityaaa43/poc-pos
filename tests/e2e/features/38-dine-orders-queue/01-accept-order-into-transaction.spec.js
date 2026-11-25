import { test, expect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';

const manager = roles.find((role) => role.key === 'manager');

test.describe('38-dine-orders-queue', () => {
    test('accept a guest order: stock is taken, a POS transaction is created and the guest sees it accepted', async ({ asRole, page: guest, arrange, uniq }) => {
        const { table_name, token } = arrange('dine.table', { area: uniq('Area'), name: uniq('M') });
        const title = uniq('Latte');
        const { product_id } = arrange('product', { title, stock: 5 });
        const note = uniq('catatan');
        const { access_token } = arrange('dine.order', { token, product_id, qty: 2, notes: note });
        arrange('open-shift', { cashier: manager.email, cash: 100000 });

        // The guest waits on the status page.
        await guest.goto(`/dine-order/${access_token}`);
        await expect(guest.getByText('Menunggu Konfirmasi', { exact: true })).toBeVisible();

        const page = await asRole(manager);
        await page.goto('/dashboard/dine-orders');
        const card = page.locator('div.rounded-2xl', { hasText: table_name });
        await expect(card).toContainText('Menunggu');
        await expect(card).toContainText('2 item');
        await expect(card).toContainText('Bayar di Kasir');
        await expect(card).toContainText(title);
        await expect(card).toContainText(/2x Rp\s*10\.000/);
        await expect(card).toContainText(`Catatan: ${note}`);
        await expect(card).toContainText(/Total: Rp\s*20\.000/);

        page.once('dialog', (confirm) => confirm.accept());
        await card.getByRole('button', { name: 'Terima' }).click();
        await expect(card).toContainText('Diterima');
        await expect(card.getByRole('button', { name: 'Terima' })).toHaveCount(0);
        await expect(card.getByRole('button', { name: 'Tolak' })).toHaveCount(0);

        // Stock leaves product and warehouse together, and the order points at a transaction.
        const state = arrange('dine.order-state', { access_token, product_id });
        expect(state).toMatchObject({ status: 'accepted', grand_total: 20000, payment_status: 'unpaid', product_stock: 3, warehouse_stock: 3 });
        expect(state.invoice).toMatch(/^TRX-/);

        // The transaction is in the cashier's history with the order total.
        await page.goto(`/dashboard/transactions/history?invoice=${state.invoice}`);
        const row = page.getByRole('row').filter({ hasText: state.invoice });
        await expect(row).toContainText(/Rp\s*20\.000/);

        // The guest's page polls every 5 s and moves on by itself.
        await expect(guest.getByTestId('dine-status')).toHaveText('Diterima', { timeout: 30_000 });
    });

    test('accepting without an open shift is refused and takes no stock', async ({ asRole, arrange, uniq }) => {
        const { table_name, token } = arrange('dine.table', { area: uniq('Area'), name: uniq('M') });
        const { product_id } = arrange('product', { title: uniq('Kue'), stock: 5 });
        const { access_token } = arrange('dine.order', { token, product_id, qty: 1 });
        // E2E users start without a shift, but an earlier step may have left one open.
        arrange('dine.close-shifts', { cashier: manager.email });

        const page = await asRole(manager);
        await page.goto('/dashboard/dine-orders');
        const card = page.locator('div.rounded-2xl', { hasText: table_name });
        page.once('dialog', (confirm) => confirm.accept());
        await card.getByRole('button', { name: 'Terima' }).click();

        await expect(page.getByText('Gagal menerima pesanan.')).toBeVisible();
        await expect(card).toContainText('Menunggu');
        expect(arrange('dine.order-state', { access_token, product_id })).toMatchObject({ status: 'submitted', product_stock: 5, warehouse_stock: 5 });
    });

    test('accepting more than the shelf holds is refused and the order stays waiting', async ({ asRole, arrange, uniq }) => {
        const { table_name, token } = arrange('dine.table', { area: uniq('Area'), name: uniq('M') });
        const { product_id } = arrange('product', { title: uniq('Donat'), stock: 5 });
        const { access_token } = arrange('dine.order', { token, product_id, qty: 4 });
        arrange('dine.set-stock', { product_id, stock: 1 }); // sold at the counter meanwhile
        arrange('open-shift', { cashier: manager.email, cash: 100000 });

        const page = await asRole(manager);
        await page.goto('/dashboard/dine-orders');
        const card = page.locator('div.rounded-2xl', { hasText: table_name });
        page.once('dialog', (confirm) => confirm.accept());
        await card.getByRole('button', { name: 'Terima' }).click();

        await expect(page.getByText('Gagal menerima pesanan.')).toBeVisible();
        expect(arrange('dine.order-state', { access_token, product_id })).toMatchObject({ status: 'submitted', invoice: null, product_stock: 1, warehouse_stock: 1 });
    });
});
