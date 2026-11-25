import { test, expect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';
import { addToCart, cashInput, change, chooseCustomer, finish, increment, openPos, productCard, rupiah, total } from '../../support/pos.js';

const cashier = roles.find((role) => role.key === 'cashier');
const superAdmin = roles.find((role) => role.key === 'super-admin');

test.describe('POS checkout: cash with change', () => {
    test.describe.configure({ timeout: 240_000 });

    test('paying cash shows the change, issues a paid receipt and takes the stock out of the outlet warehouse', async ({ asRole, arrange, uniq }) => {
        const product = arrange('product', { title: uniq('cash'), stock: 7 });
        const customer = arrange('pos.customer', { name: uniq('Budi') });
        arrange('pos.open-shift', { cash: 100000 });
        const page = await asRole(cashier);

        await openPos(page);
        await addToCart(page, product.title);
        await increment(page);
        await expect(page.getByText('Rp 10.000 × 2')).toBeVisible();
        await increment(page);
        await expect(page.getByText('Rp 10.000 × 3')).toBeVisible();
        await expect(total(page)).toHaveText(rupiah(30000));

        // The sale needs a customer, then the exact amount.
        const submit = page.getByRole('button', { name: /^(Pilih Pelanggan|Kurang|Selesaikan)/ });
        await expect(submit).toHaveText('Pilih Pelanggan');
        await expect(submit).toBeDisabled();
        await chooseCustomer(page, customer.name);
        await expect(submit).toHaveText(`Kurang ${rupiah(30000)}`);

        // Short of the total: the button says how much is missing and stays disabled.
        await cashInput(page).fill('20000');
        await expect(submit).toHaveText(`Kurang ${rupiah(10000)}`);
        await expect(submit).toBeDisabled();
        await expect(change(page)).toHaveText(rupiah(0));

        // More than the total: the change is shown before paying.
        await cashInput(page).fill('50000');
        await expect(change(page)).toHaveText(rupiah(20000));
        await expect(submit).toHaveText('Selesaikan Transaksi');
        const invoice = await finish(page);

        // Receipt: paid in cash, with the amount handed over and the change given back.
        await expect(page.getByText('Lunas', { exact: true })).toBeVisible();
        await expect(page.getByText(customer.name)).toBeVisible();
        await expect(page.getByText(product.title, { exact: true })).toBeVisible();
        await expect(page.getByText('Total', { exact: true }).locator('xpath=..')).toContainText(rupiah(30000));
        await expect(page.getByText('Tunai', { exact: true }).last().locator('xpath=..')).toContainText(rupiah(50000));
        await expect(page.getByText('Kembali', { exact: true }).locator('xpath=..')).toContainText(rupiah(20000));

        // Back at the till: the cart is empty and 7 - 3 = 4 are left (the card flags 5 or fewer).
        await page.getByRole('link', { name: 'Kembali ke kasir' }).click();
        await expect(page.getByText('Keranjang kosong', { exact: true })).toBeVisible();
        await expect(productCard(page, product.title).getByText('Sisa 4')).toBeVisible();

        // The stock book records the sale against the outlet warehouse, 7 -> 4.
        const admin = await asRole(superAdmin);
        await admin.goto(`/dashboard/stock-mutations?product_id=${product.product_id}`);
        const row = admin.getByRole('row').filter({ hasText: product.title });
        await expect(row).toContainText('out');
        await expect(row).toContainText('7 → 4');
        await expect(row).toContainText('Gudang E2E');
        await expect(row).toContainText(`Penjualan ${invoice}`);
    });

    test('checkout is refused when the shelf was emptied after the cart was filled', async ({ asRole, arrange, uniq }) => {
        const product = arrange('product', { title: uniq('cash-race'), stock: 3 });
        const customer = arrange('pos.customer', { name: uniq('Siti') });
        arrange('pos.open-shift', { cash: 100000 });
        arrange('pos.cart', { product_id: product.product_id, qty: 3 });
        const page = await asRole(cashier);

        await openPos(page);
        await expect(page.getByText('Rp 10.000 × 3')).toBeVisible();
        await chooseCustomer(page, customer.name);
        await page.getByRole('button', { name: 'Uang pas' }).click();

        // Someone else took 2 of the 3 meanwhile.
        arrange('pos.set-stock', { product_id: product.product_id, stock: 1 });
        await page.getByRole('button', { name: 'Selesaikan Transaksi' }).click();

        await expect(page.getByText('Gagal menyimpan transaksi')).toBeVisible();
        await expect(page).toHaveURL(/\/dashboard\/transactions$/);
        await expect(page.getByText('Rp 10.000 × 3')).toBeVisible();
    });
});
