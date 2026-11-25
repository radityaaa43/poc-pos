import { test, expect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';
import { addToCart, cardButton, increment, openPos, productCard, rupiah, total } from '../../support/pos.js';

const cashier = roles.find((role) => role.key === 'cashier');

test.describe('POS cart: add, change quantity, remove', () => {
    test.describe.configure({ timeout: 180_000 });

    test('a product from the grid joins the cart; + and - change qty and total; the trash removes it', async ({ asRole, arrange, uniq }) => {
        const first = arrange('product', { title: uniq('cart-a'), stock: 10 });
        const second = arrange('product', { title: uniq('cart-b'), stock: 10 });
        arrange('pos.open-shift', { cash: 100000 });
        const page = await asRole(cashier);

        await openPos(page);
        await expect(page.getByText('Keranjang kosong', { exact: true })).toBeVisible();
        await expect(page.getByRole('button', { name: 'Keranjang Kosong' })).toBeDisabled();

        await addToCart(page, first.title);
        await expect(page.getByText('Rp 10.000 × 1')).toBeVisible();
        await expect(total(page)).toHaveText(rupiah(10000));

        // Adding the same product again raises its qty instead of making a second row.
        await addToCart(page, first.title);
        await expect(page.getByText('Rp 10.000 × 2')).toBeVisible();
        await expect(total(page)).toHaveText(rupiah(20000));

        await increment(page);
        await expect(page.getByText('Rp 10.000 × 3')).toBeVisible();
        await expect(total(page)).toHaveText(rupiah(30000));

        await page.getByRole('button', { name: '-', exact: true }).click();
        await expect(page.getByText('Rp 10.000 × 2')).toBeVisible();
        await expect(total(page)).toHaveText(rupiah(20000));

        // A second product: two rows; remove the first one with its trash button.
        await addToCart(page, second.title);
        await expect(page.getByText('3 item')).toBeVisible();
        await expect(total(page)).toHaveText(rupiah(30000));

        await page.getByText(first.title, { exact: true }).last().locator('xpath=ancestor::div[contains(@class,"group")][1]').getByRole('button').last().click();
        await expect(page.getByText('Item dihapus dari keranjang')).toBeVisible();
        await expect(page.getByText('Rp 10.000 × 2')).toHaveCount(0);
        await expect(page.getByText('1 item')).toBeVisible();
        await expect(total(page)).toHaveText(rupiah(10000));

        // The cart is the cashier's own and survives a reload.
        await page.reload();
        await expect(page.getByText('Rp 10.000 × 1')).toBeVisible();
        await expect(total(page)).toHaveText(rupiah(10000));
    });

    test('the cart takes as many as the outlet warehouse holds', async ({ asRole, arrange, uniq }) => {
        const product = arrange('product', { title: uniq('cart-stock'), stock: 2 });
        arrange('pos.open-shift', { cash: 100000 });
        const page = await asRole(cashier);

        await openPos(page);
        // At most 5 left shows as a badge on the card.
        await expect(productCard(page, product.title).getByText('Sisa 2')).toBeVisible();
        await addToCart(page, product.title);
        await increment(page);
        await expect(page.getByText('Rp 10.000 × 2')).toBeVisible();
        await expect(total(page)).toHaveText(rupiah(20000));
    });

    test('pressing + at the stock limit says why and keeps the qty', async ({ asRole, arrange, uniq }) => {
        const product = arrange('product', { title: uniq('cart-limit'), stock: 2 });
        arrange('pos.open-shift', { cash: 100000 });
        arrange('pos.cart', { product_id: product.product_id, qty: 2 });
        const page = await asRole(cashier);

        await openPos(page);
        const refused = expect(page.getByText('Stok tidak mencukupi. Tersedia: 2')).toBeVisible();
        await increment(page);
        await refused;
        await expect(page.getByText('Rp 10.000 × 2')).toBeVisible();
    });

    test('adding the same product from the grid past the stock is refused', async ({ asRole, arrange, uniq }) => {
        const product = arrange('product', { title: uniq('cart-over'), stock: 2 });
        arrange('pos.open-shift', { cash: 100000 });
        arrange('pos.cart', { product_id: product.product_id, qty: 2 });
        const page = await asRole(cashier);

        await openPos(page);
        const refused = expect(page.getByText('Stok tidak mencukupi. Tersedia: 2')).toBeVisible();
        await addToCart(page, product.title);
        await refused;
        await expect(page.getByText('Rp 10.000 × 2')).toBeVisible();
        await expect(total(page)).toHaveText(rupiah(20000));
    });

    test('a product with no stock in the outlet warehouse is not offered on the grid', async ({ asRole, arrange, uniq }) => {
        const product = arrange('product', { title: uniq('cart-empty'), stock: 0 });
        arrange('pos.open-shift', { cash: 100000 });
        const page = await asRole(cashier);

        await openPos(page);
        await expect(page.getByRole('heading', { name: 'Produk E2E 1', level: 3, exact: true })).toBeVisible();
        await expect(page.getByRole('heading', { name: product.title, level: 3, exact: true })).toHaveCount(0);
        await page.getByPlaceholder(/Cari produk/).fill(product.title);
        await expect(page.getByText('Produk tidak ditemukan')).toBeVisible();
        await expect(page.getByText('Keranjang kosong', { exact: true })).toBeVisible();
    });
});
