import { test, expect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';

const manager = roles.find((role) => role.key === 'manager');

// Issue #128: an accepted dine order is an open bill and payment comes first. The kitchen (Mulai Buat) only works a
// paid order; then Siap and Serahkan, and serving completes it. The transaction made at accept is the one paid. A
// pending transfer, QRIS, Midtrans or Xendit payment is not paid either; those need a live gateway or bank, so they are
// covered by tests/Feature/DineIn/DineOrderPaymentTest.php instead.
test.describe('38-dine-orders-queue', () => {
    test('unpaid orders wait for payment, pay from Transactions History, then prepare, ready and serve: the order completes and the guest sees Selesai', async ({ asRole, page: guest, arrange, uniq }) => {
        const { table_name, token } = arrange('dine.table', { area: uniq('Area'), name: uniq('M') });
        const { product_id } = arrange('product', { title: uniq('Rujak'), stock: 5 });
        const { access_token } = arrange('dine.order', { token, product_id, qty: 2 });
        arrange('open-shift', { cashier: manager.email, cash: 100000 });

        const page = await asRole(manager);
        await page.goto('/dashboard/dine-orders');
        const card = page.locator('div.rounded-2xl', { hasText: table_name });
        page.once('dialog', (confirm) => confirm.accept());
        await card.getByRole('button', { name: 'Terima' }).click();
        const accepted = arrange('dine.order-state', { access_token, product_id });
        expect(accepted).toMatchObject({ status: 'accepted', payment_status: 'unpaid', payment_method: 'open_bill', preparing: false, ready: false, served: false, receivables: 0, product_stock: 3 });

        // Accepted but unpaid: "Menunggu Pembayaran", no Mulai Buat, and not in the kitchen view.
        await expect(card).toContainText('Menunggu Pembayaran');
        await expect(card.getByRole('button', { name: 'Mulai Buat' })).toHaveCount(0);
        await page.getByRole('button', { name: 'Dapur', exact: true }).click();
        await expect(card).toHaveCount(0);
        await page.getByRole('button', { name: 'Menunggu Pembayaran', exact: true }).click();
        await expect(card).toHaveCount(1);
        await guest.goto(`/dine-order/${access_token}`);
        await expect(guest.getByTestId('dine-status')).toHaveText('Menunggu Pembayaran');

        // The open bill shows as "Belum Lunas · Meja X" with a Bayar action; the cashier picks cash and gets change.
        await page.goto(`/dashboard/transactions/history?invoice=${accepted.invoice}`);
        const row = page.getByRole('row').filter({ hasText: accepted.invoice });
        await expect(row).toContainText(`Belum Lunas · ${table_name}`);
        await row.getByRole('button', { name: 'Bayar' }).click();
        const dialog = page.getByRole('dialog');
        await dialog.getByLabel('Uang diterima').fill('25000');
        await expect(dialog).toContainText(/Kembalian: Rp\s*5\.000/);
        await dialog.getByRole('button', { name: 'Bayar', exact: true }).click();
        await page.waitForURL(new RegExp(`/transactions/${accepted.invoice}/print`));

        // Same transaction, now paid; stock is not taken again; the order is still accepted and now in the kitchen view.
        expect(arrange('dine.order-state', { access_token, product_id })).toMatchObject({
            status: 'accepted', invoice: accepted.invoice, payment_status: 'paid', payment_method: 'cash', cash: 25000, change: 5000, served: false, receivables: 0, product_stock: 3,
        });
        await page.goto('/dashboard/dine-orders');
        await page.getByRole('button', { name: 'Dapur', exact: true }).click();
        await expect(card.getByText('Lunas', { exact: true })).toBeVisible();
        await guest.reload();
        await expect(guest.getByTestId('dine-status')).toHaveText('Diterima');

        // Each step only from the previous one: nothing to serve before the kitchen is done.
        await expect(card.getByRole('button', { name: 'Serahkan' })).toHaveCount(0);
        await card.getByRole('button', { name: 'Mulai Buat' }).click();
        await expect(card.getByRole('button', { name: 'Siap', exact: true })).toBeVisible();
        await guest.reload();
        await expect(guest.getByTestId('dine-status')).toHaveText('Sedang dibuat');

        await card.getByRole('button', { name: 'Siap', exact: true }).click();
        await page.getByRole('button', { name: 'Penyerahan', exact: true }).click();
        await expect(card.getByRole('button', { name: 'Serahkan' })).toBeVisible();
        await guest.reload();
        await expect(guest.getByTestId('dine-status')).toHaveText('Siap');

        // The bill is already paid, so serving completes the order and takes it off the queue.
        await card.getByRole('button', { name: 'Serahkan' }).click();
        await expect(card).toHaveCount(0);
        expect(arrange('dine.order-state', { access_token })).toMatchObject({ status: 'completed', preparing: true, ready: true, served: true });
        await guest.reload();
        await expect(guest.getByTestId('dine-status')).toHaveText('Selesai');
    });

    test('pay from the POS open bills, then the kitchen takes it: Belum Lunas shows Menunggu Pembayaran until it is paid', async ({ asRole, page: guest, arrange, uniq }) => {
        const { table_name, token } = arrange('dine.table', { area: uniq('Area'), name: uniq('M') });
        const { product_id } = arrange('product', { title: uniq('Pisang'), stock: 5 });
        const { access_token } = arrange('dine.order', { token, product_id, qty: 1 });
        arrange('open-shift', { cashier: manager.email, cash: 100000 });

        const page = await asRole(manager);
        await page.goto('/dashboard/dine-orders');
        const card = page.locator('div.rounded-2xl', { hasText: table_name });
        page.once('dialog', (confirm) => confirm.accept());
        await card.getByRole('button', { name: 'Terima' }).click();
        await expect(card.getByRole('button', { name: 'Mulai Buat' })).toHaveCount(0);

        await page.goto('/dashboard/transactions');
        await page.getByRole('button', { name: /Belum Lunas/ }).click();
        const bill = page.getByTestId('open-bills').getByRole('listitem').filter({ hasText: table_name });
        await expect(bill).toContainText('Menunggu Pembayaran');
        await bill.getByRole('button', { name: 'Bayar' }).click();
        const dialog = page.getByRole('dialog');
        await dialog.getByLabel('Uang diterima').fill('10000');
        await dialog.getByRole('button', { name: 'Bayar', exact: true }).click();
        await page.waitForURL(/\/transactions\/TRX-[A-Z0-9]+\/print/);
        expect(arrange('dine.order-state', { access_token })).toMatchObject({ status: 'accepted', payment_status: 'paid', payment_method: 'cash', receivables: 0 });

        await page.goto('/dashboard/dine-orders');
        await card.getByRole('button', { name: 'Mulai Buat' }).click();
        await card.getByRole('button', { name: 'Siap', exact: true }).click();
        await card.getByRole('button', { name: 'Serahkan' }).click();
        await expect(card).toHaveCount(0);

        expect(arrange('dine.order-state', { access_token })).toMatchObject({ status: 'completed', served: true, payment_status: 'paid' });
        await guest.goto(`/dine-order/${access_token}`);
        await expect(guest.getByTestId('dine-status')).toHaveText('Selesai');
    });
});
