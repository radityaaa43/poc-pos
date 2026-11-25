import { test, expect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';
import { addToCart, chooseCustomer, finish, openPos, rupiah, total } from '../../support/pos.js';

const cashier = roles.find((role) => role.key === 'cashier');
const superAdmin = roles.find((role) => role.key === 'super-admin');

test.describe('POS checkout: walk-in sale and selling by the box', () => {
    test.describe.configure({ timeout: 240_000 });

    // Question #133: Index.jsx and TransactionController allow a sale without a customer, but the button needs one.
    test.fixme('a walk-in sale without a customer can be completed and shows as Umum in the history', async ({ asRole, arrange, uniq }) => {
        const product = arrange('product', { title: uniq('walk-in'), stock: 10 });
        arrange('pos.open-shift', { cash: 100000 });
        const page = await asRole(cashier);

        await openPos(page);
        await addToCart(page, product.title);
        await page.getByRole('button', { name: 'Uang pas' }).click();
        await expect(page.getByRole('button', { name: 'Selesaikan Transaksi' })).toBeEnabled();
        const invoice = await finish(page);

        await page.goto('/dashboard/transactions/history');
        await expect(page.getByRole('row').filter({ hasText: invoice })).toContainText('Umum');
    });

    test('selling a box charges the box price and takes the whole box (12 pcs) out of the warehouse stock', async ({ asRole, arrange, uniq }) => {
        const product = arrange('pos.unit-product', { title: uniq('box-sale'), stock: 30, factor: 12 });
        const customer = arrange('pos.customer', { name: uniq('Hadi') });
        arrange('pos.open-shift', { cash: 100000 });
        const page = await asRole(cashier);

        await openPos(page);
        await addToCart(page, product.title, { unit: 'BOX' });
        await chooseCustomer(page, customer.name);
        await expect(total(page)).toHaveText(rupiah(108000));
        await page.getByRole('button', { name: 'Uang pas' }).click();
        const invoice = await finish(page);
        await expect(page.getByText('Lunas', { exact: true })).toBeVisible();
        await expect(page.getByRole('row').filter({ hasText: product.title })).toContainText(rupiah(108000));

        // Stock is kept in pieces: 30 - 12 = 18, in the warehouse and in the stock book.
        const admin = await asRole(superAdmin);
        await admin.goto(`/dashboard/stock-mutations?product_id=${product.product_id}`);
        const row = admin.getByRole('row').filter({ hasText: product.title });
        await expect(row).toContainText('30 → 18');
        await expect(row).toContainText(`Penjualan ${invoice}`);
    });
});
