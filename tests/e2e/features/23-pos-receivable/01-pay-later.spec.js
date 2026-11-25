import { test, expect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';
import { addToCart, chooseCustomer, finish, increment, openPos, productCard, rupiah, total } from '../../support/pos.js';
import { field } from '../../support/inventory.js';

const cashier = roles.find((role) => role.key === 'cashier');
// The checkbox is visually hidden (a styled switch sits on top), so act on it directly.
const payLaterToggle = (page) => ({
    check: () => page.getByRole('checkbox').check({ force: true }),
    uncheck: () => page.getByRole('checkbox').uncheck({ force: true }),
});

test.describe('POS checkout: pay later (nota barang) to a customer', () => {
    test.describe.configure({ timeout: 240_000 });

    test('a pay-later sale to a credit customer needs a due date, then becomes a receivable', async ({ asRole, arrange, uniq }) => {
        const product = arrange('product', { title: uniq('credit'), stock: 5 });
        const customer = arrange('pos.customer', { name: uniq('Warung Bu Tini') });
        // Bayar Belakangan is off until the outlet turns it on, and only a "Boleh piutang" customer leaves a receivable.
        arrange('paylater.setting', { enabled: 1 });
        arrange('paylater.credit', { customer_id: customer.customer_id });
        arrange('pos.open-shift', { cash: 100000 });
        const page = await asRole(cashier);

        await openPos(page);
        await addToCart(page, product.title);
        await increment(page);
        await expect(page.getByText('Rp 10.000 × 2')).toBeVisible();

        // Turning pay-later on swaps the cash panel for a due-date field.
        await payLaterToggle(page).check();
        await expect(page.getByText('Pembayaran Tunai')).toHaveCount(0);
        // No customer is needed any more (REQ-003 BR-7): a guest leaves a note instead, so no due date is asked yet.
        await expect(page.getByText('Catatan (wajib untuk tamu)')).toBeVisible();
        await expect(page.getByText('Tanggal Jatuh Tempo')).toHaveCount(0);

        // With a customer but no due date the sale is refused on the spot.
        await chooseCustomer(page, customer.name);
        await expect(page.getByText('Tanggal Jatuh Tempo')).toBeVisible(); // a credit customer picks a due date
        await page.getByRole('button', { name: 'Selesaikan Transaksi' }).click();
        await expect(page.getByText('Isi tanggal jatuh tempo untuk nota barang')).toBeVisible();
        await expect(page).toHaveURL(/\/dashboard\/transactions$/);

        await field(page, 'Tanggal Jatuh Tempo').fill('2026-12-31');
        const invoice = await finish(page);

        // The receipt is an unpaid note with its due date; nothing was collected.
        await expect(page.getByText('Piutang', { exact: true }).first()).toBeVisible();
        await expect(page.getByText('Belum Lunas', { exact: true })).toBeVisible();
        await expect(page.getByText('Jatuh tempo: 2026-12-31')).toBeVisible();
        await expect(page.getByText(customer.name)).toBeVisible();

        // The history flags it as a receivable instead of paid.
        await page.goto('/dashboard/transactions/history');
        const row = page.getByRole('row').filter({ hasText: invoice });
        await expect(row).toContainText('Piutang');
        await expect(row).toContainText(rupiah(20000));
        await expect(row).not.toContainText('Lunas');

        // The receivable is listed for collection with the customer and the amount.
        await page.goto('/dashboard/receivables');
        await page.getByPlaceholder('Cari invoice / nomor nota').fill(invoice);
        await page.getByRole('button', { name: 'Terapkan' }).click();
        // The search left only this note; the list is not a semantic table, so read it by text.
        const list = page.getByRole('main');
        await expect(list.getByText(invoice, { exact: true })).toBeVisible();
        await expect(list.getByText(customer.name)).toBeVisible();
        await expect(list.getByText(rupiah(20000)).first()).toBeVisible();
        await expect(list.getByText('Belum Lunas', { exact: true }).last()).toBeVisible();

        // Goods left the shelf at once: 5 - 2 = 3 (the card flags 5 or fewer).
        await page.goto('/dashboard/transactions');
        await expect(productCard(page, product.title).getByText('Sisa 3')).toBeVisible();
    });

    test('pay later is dropped when the toggle is turned off again', async ({ asRole, arrange, uniq }) => {
        const product = arrange('product', { title: uniq('credit-off'), stock: 5 });
        arrange('paylater.setting', { enabled: 1 });
        arrange('pos.open-shift', { cash: 100000 });
        const page = await asRole(cashier);

        await openPos(page);
        await addToCart(page, product.title);
        await payLaterToggle(page).check();
        await expect(page.getByText('Catatan (wajib untuk tamu)')).toBeVisible(); // a guest: the note, not a due date
        await payLaterToggle(page).uncheck();
        await expect(page.getByText('Catatan (wajib untuk tamu)')).toHaveCount(0);
        await expect(page.getByText('Pembayaran Tunai')).toBeVisible();
        await expect(total(page)).toHaveText(rupiah(10000));
    });
});
