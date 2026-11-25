import { test, expect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';

const manager = roles.find((role) => role.key === 'manager');

// Issue #128: an accepted dine order is an open bill. Serving (staff queue) and paying (cashier) are
// separate; the order completes once it is both, in either order, and the transaction made at accept is the one paid.
test.describe('38-dine-orders-queue', () => {
    test('pay from Transactions History first, then serve: the order completes and the guest sees Selesai', async ({ asRole, page: guest, arrange, uniq }) => {
        const { table_name, token } = arrange('dine.table', { area: uniq('Area'), name: uniq('M') });
        const { product_id } = arrange('product', { title: uniq('Rujak'), stock: 5 });
        const { access_token } = arrange('dine.order', { token, product_id, qty: 2 });
        arrange('open-shift', { cashier: manager.email, cash: 100000 });

        const page = await asRole(manager);
        await page.goto('/dashboard/dine-orders');
        const card = page.locator('div.rounded-2xl', { hasText: table_name });
        page.once('dialog', (confirm) => confirm.accept());
        await card.getByRole('button', { name: 'Terima' }).click();
        await expect(card).toContainText('Belum Lunas');
        const accepted = arrange('dine.order-state', { access_token, product_id });
        expect(accepted).toMatchObject({ status: 'accepted', payment_status: 'unpaid', payment_method: 'open_bill', served: false, receivables: 0, product_stock: 3 });

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

        // Same transaction, now paid; stock is not taken again; paid but not served, so it stays in the queue.
        expect(arrange('dine.order-state', { access_token, product_id })).toMatchObject({
            status: 'accepted', invoice: accepted.invoice, payment_status: 'paid', payment_method: 'cash', cash: 25000, change: 5000, served: false, receivables: 0, product_stock: 3,
        });
        await page.goto('/dashboard/dine-orders');
        await expect(card.getByText('Lunas', { exact: true })).toBeVisible();
        await card.getByRole('button', { name: 'Sajikan' }).click();
        await expect(card).toHaveCount(0);

        expect(arrange('dine.order-state', { access_token })).toMatchObject({ status: 'completed', served: true });
        await guest.goto(`/dine-order/${access_token}`);
        await expect(guest.getByText('Selesai', { exact: true })).toBeVisible();
    });

    test('serve first, then pay from the POS open bills: it leaves the queue when served and completes when paid', async ({ asRole, page: guest, arrange, uniq }) => {
        const { table_name, token } = arrange('dine.table', { area: uniq('Area'), name: uniq('M') });
        const { product_id } = arrange('product', { title: uniq('Pisang'), stock: 5 });
        const { access_token } = arrange('dine.order', { token, product_id, qty: 1 });
        arrange('open-shift', { cashier: manager.email, cash: 100000 });

        const page = await asRole(manager);
        await page.goto('/dashboard/dine-orders');
        const card = page.locator('div.rounded-2xl', { hasText: table_name });
        page.once('dialog', (confirm) => confirm.accept());
        await card.getByRole('button', { name: 'Terima' }).click();
        await card.getByRole('button', { name: 'Sajikan' }).click();
        await page.reload();
        await expect(card).toHaveCount(0);

        // Served but unpaid: not completed, the guest is told to pay, and it waits in Open Bills on the POS page.
        expect(arrange('dine.order-state', { access_token })).toMatchObject({ status: 'accepted', served: true, payment_status: 'unpaid' });
        await guest.goto(`/dine-order/${access_token}`);
        await expect(guest.getByText('Disajikan', { exact: true })).toBeVisible();

        await page.goto('/dashboard/transactions');
        await page.getByRole('button', { name: /Tagihan Terbuka/ }).click();
        const bill = page.getByTestId('open-bills').getByRole('listitem').filter({ hasText: table_name });
        await expect(bill).toContainText('Sudah disajikan');
        await bill.getByRole('button', { name: 'Bayar' }).click();
        const dialog = page.getByRole('dialog');
        await dialog.getByLabel('Uang diterima').fill('10000');
        await dialog.getByRole('button', { name: 'Bayar', exact: true }).click();
        await page.waitForURL(/\/transactions\/TRX-[A-Z0-9]+\/print/);

        expect(arrange('dine.order-state', { access_token })).toMatchObject({ status: 'completed', payment_status: 'paid', payment_method: 'cash', receivables: 0 });
        await guest.goto(`/dine-order/${access_token}`);
        await expect(guest.getByText('Selesai', { exact: true })).toBeVisible();
    });
});
