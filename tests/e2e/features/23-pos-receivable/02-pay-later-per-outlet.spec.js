import { test, expect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';
import { addToCart, chooseCustomer, finish, openPos } from '../../support/pos.js';
import { field } from '../../support/inventory.js';

const cashier = roles.find((role) => role.key === 'cashier');

// Bayar Belakangan is an outlet setting (REQ-003 #240): off by default, a receivable only for a "Boleh piutang"
// customer, a guest bill needs a note, and the sale reaches the kitchen at once, unpaid.
test.describe('POS pay later per outlet', () => {
    test.describe.configure({ timeout: 240_000 });

    test('@REQ-003-AC-03 a pay-later sale reaches the kitchen at once, unpaid, and leaves a receivable for a credit customer', async ({ asRole, arrange, uniq }) => {
        const dish = arrange('product', { title: uniq('Soto'), stock: 5 });
        const customer = arrange('pos.customer', { name: uniq('Warung Bu Ani') });
        arrange('kitchen.flag', { product_id: dish.product_id });
        arrange('paylater.setting', { enabled: 1 });
        arrange('paylater.credit', { customer_id: customer.customer_id });
        arrange('pos.open-shift', { cash: 100000 });
        const page = await asRole(cashier);

        await openPos(page);
        await addToCart(page, dish.title);
        await chooseCustomer(page, customer.name);
        await page.getByRole('checkbox').check({ force: true });
        await field(page, 'Tanggal Jatuh Tempo').fill('2026-12-31');
        const invoice = await finish(page);

        expect(arrange('paylater.sale-state', { invoice })).toMatchObject({ payment_status: 'unpaid', payment_method: 'pay_later', is_pay_later: true, receivables: 1 });
        expect(arrange('kitchen.items', { invoice })).toEqual({ count: 1, product_ids: [dish.product_id], statuses: ['accepted'] });
    });

    test('@REQ-003-AC-04 when the outlet has not turned Bayar Belakangan on, the POS does not offer it', async ({ asRole, arrange, uniq }) => {
        const product = arrange('product', { title: uniq('Teh'), stock: 5 });
        arrange('paylater.setting', { enabled: 0 });
        arrange('pos.open-shift', { cash: 100000 });
        const page = await asRole(cashier);

        await openPos(page);
        await addToCart(page, product.title);

        await expect(page.getByText('Pembayaran Tunai')).toBeVisible();
        await expect(page.getByText('Bayar Belakangan', { exact: true })).toHaveCount(0);
        await expect(page.getByRole('checkbox')).toHaveCount(0);
    });

    test('@REQ-003-AC-17 a guest pay-later bill needs a note and leaves no receivable', async ({ asRole, arrange, uniq }) => {
        const product = arrange('product', { title: uniq('Kopi'), stock: 5 });
        arrange('paylater.setting', { enabled: 1 });
        arrange('pos.open-shift', { cash: 100000 });
        const page = await asRole(cashier);

        await openPos(page);
        await addToCart(page, product.title);
        await page.getByRole('checkbox').check({ force: true });

        // One character is too short: refused with the note rule and nothing is saved.
        await field(page, 'Catatan (wajib untuk tamu)').fill('A');
        await page.getByRole('button', { name: 'Selesaikan Transaksi' }).click();
        await expect(page).toHaveURL(/\/dashboard\/transactions$/);
        await expect(page.getByText('Transaksi berhasil!')).toHaveCount(0);

        await field(page, 'Catatan (wajib untuk tamu)').fill('Meja 4');
        const invoice = await finish(page);

        expect(arrange('paylater.sale-state', { invoice })).toMatchObject({ payment_status: 'unpaid', payment_method: 'pay_later', is_pay_later: true, receivables: 0 });
    });
});
